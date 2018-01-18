<?php
/*
 * (c) Anthony Benkhebbab <rewieer@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Rewieer\Serializer\Normalizer;

use Rewieer\Serializer\Context;
use Rewieer\Serializer\Exception\MethodException;
use Rewieer\Serializer\Exception\PrivatePropertyException;
use Rewieer\Serializer\PropertyAccessor;
use Rewieer\Serializer\Serializer;
use Rewieer\Serializer\SerializerTools;

class ObjectNormalizer implements NormalizerInterface {
  /**
   * We hold a copy of the serializer because the ObjectNormalize supports normalizing
   * nested objects with user-set normalizers.
   * We call class-defined normalizers in priority and fallback to object normalizer otherwise
   * @var Serializer
   */
  private $serializer;

  public function __construct(Serializer $serializer) {
    $this->serializer = $serializer;
  }

  /**
   * Normalize the value
   * @param $value
   * @param Context|null $context
   * @return array
   * @throws PrivatePropertyException
   * @throws \Exception
   */
  private function normalizeValue($value, Context $context = null) {
    $normalizer = $this->serializer->getNormalizer($value);
    if ($normalizer) {
      return $normalizer->normalize($value, $context);
    }

    return $this->normalize($value, $context);
  }

  /**
   * Normalize the object
   * @param $data
   * @param Context|null $context
   * @return array
   * @throws \Exception
   * @throws PrivatePropertyException
   */
  public function normalize($data, Context $context = null) {
    $metadata = null;
    $out = [];
    $accessor = new PropertyAccessor($data);

    if ($context && $context->getMetadataCollection()) {
      $metadata = $context->getMetadataCollection()->getOrNull(get_class($data));
    }

    foreach ($accessor->getProperties() as $property) {
      if ($context && $context->getView() !== null) {

        // We get the data corresponding to the current path
        if ($metadata !== null && is_array($context->getView()) === false) {
          $viewData = $metadata->getViewOrNull($context->getView());
        } else {
          $viewData = $context->getView();
        }

        $viewData = SerializerTools::deepGet($viewData, $context->getNavigator()->getPath());

        // If there's any we filter out unwanted stuff
        if ($viewData) {
          if (in_array($property->name, $viewData) === false && array_key_exists($property->name, $viewData) === false) {
            continue;
          }
        }
      }

      $value = null;
      $valueHasBeenSet = false; // custom getter can return null, in which case we don't want to lookup for accessors

      if ($metadata) {
        $propertyConfiguration = $metadata->getAttributeOrNull($property->getName());
        if ($propertyConfiguration && array_key_exists("getter", $propertyConfiguration)) {
          $getter = $propertyConfiguration["getter"];
          if ($accessor->hasMethod($getter) === false || $accessor->isPublic($getter) === false) {
            throw new MethodException($propertyConfiguration["getter"], $accessor->getClassName(), $property->name);
          }

          $valueHasBeenSet = true;
          $value = call_user_func([$data, $getter]);
        }
      }

      if ($valueHasBeenSet === false) {
        try {
          $value = $accessor->get($property->name, $data);
        } catch (PrivatePropertyException $e) {
          // If we don't find any accessor we consider the user doesn't want it to be normalized
          continue;
        }
      }

      if (is_object($value)) {
        if ($context)
          $context->getNavigator()->down($property->name);

        $value = $this->normalizeValue($value, $context);

        if ($context)
          $context->getNavigator()->up();
      } else if (is_array($value)) {
        // We don't handle associative arrays so we assume this is a genuine array
        $value = array_map(function($notNormalizedValue) use ($property, $context) {
          if ($context)
            $context->getNavigator()->down($property->name);

          $normalizedValue = $this->normalizeValue($notNormalizedValue, $context);

          if ($context)
            $context->getNavigator()->up();

          return $normalizedValue;
        }, $value);
      }

      $out[$property->getName()] = $value;
    }

    return $out;
  }

  /**
   * TODO : do nested denormalization
   * @param array $data
   * @param $object
   * @param Context|null $context
   * @return mixed
   * @throws PrivatePropertyException
   */
  public function denormalize(array $data, $object, Context $context = null) {
    $accessor = new PropertyAccessor($object);
    foreach ($accessor->getProperties() as $property) {
      if (array_key_exists($property->getName(), $data) === false) {
        continue;
      }

      $value = $data[$property->getName()];
      if ($context) {
        $metadata = $context->getMetadataCollection()->getOrNull(get_class($object));
        if ($metadata) {
          $propertyConfiguration = $metadata->getAttributeOrNull($property->getName());
          if ($propertyConfiguration) {
            if (array_key_exists("class", $propertyConfiguration) && is_array($value)) {
              $item = new $propertyConfiguration["class"];
              $value = $this->denormalize($value, $item, $context);
            } else if (array_key_exists("denormalizer", $propertyConfiguration) && is_array($value)) {
              $value = $propertyConfiguration["denormalizer"]($value, $object, $context);
            } else if (array_key_exists("type", $propertyConfiguration)) {
              switch ($propertyConfiguration["type"]) {
                case "int":
                  $value = intval($value);
                  break;
                case "float":
                  $value = floatval($value);
                  break;
              }
            }
          }
        }
      }

      $accessor->set($property->name, $object, $value);
    }

    return $object;
  }

}