<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Form\Validation;

use Mautic\PluginBundle\Entity\Integration;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Validation for the HTTP token field in plugin credentials.
 */
final class HttpTokenConstraintFactory
{
    /**
     * Require a token when the integration is published and none is stored yet.
     * When a token already exists, an empty password field means “keep current value”.
     */
    public static function tokenRequired(bool $hasExistingToken): Callback
    {
        return new Callback(
            static function (mixed $value, ExecutionContextInterface $context) use ($hasExistingToken): void {
                if ($hasExistingToken && '' === \trim((string) $value)) {
                    return;
                }

                $root = $context->getRoot()->getData();
                if (!$root instanceof Integration || !$root->getIsPublished()) {
                    return;
                }

                if ('' === \trim((string) $value)) {
                    $context->buildViolation('mautic.core.value.required')->addViolation();
                }
            }
        );
    }
}
