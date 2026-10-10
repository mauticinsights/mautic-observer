<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Tests\Form\Type;

use Mautic\IntegrationsBundle\Integration\Interfaces\IntegrationInterface;
use Mautic\PluginBundle\Entity\Integration;
use MauticPlugin\MauticInsightsBundle\Form\Type\AuthConfigType;
use MauticPlugin\MauticInsightsBundle\Form\Validation\HttpTokenConstraintFactory;
use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Regression: nested {@see Callback} caused TypeError in CallbackValidator on save
 * (method_exists(): Argument #2 must be string, Callback given).
 */
final class AuthConfigTypeTest extends TestCase
{
    public function testTokenRequiredCallbackIsNotDoubleWrapped(): void
    {
        $constraint = HttpTokenConstraintFactory::tokenRequired(false);

        $this->assertInstanceOf(Callback::class, $constraint);
        $this->assertIsCallable($constraint->callback);
        $this->assertNotInstanceOf(Callback::class, $constraint->callback);
    }

    public function testCallbackRequiresTokenWhenPublishedAndNoExistingToken(): void
    {
        $constraint = HttpTokenConstraintFactory::tokenRequired(false);
        $integration = new Integration(true, [], InsightsApiConfig::NAME, []);
        $violations = [];

        ($constraint->callback)('', $this->createContext($integration, $violations));

        $this->assertNotEmpty($violations);
    }

    public function testCallbackAcceptsNewTokenWhenPublished(): void
    {
        $constraint = HttpTokenConstraintFactory::tokenRequired(false);
        $integration = new Integration(true, [], InsightsApiConfig::NAME, []);
        $violations = [];

        ($constraint->callback)('brand-new-token', $this->createContext($integration, $violations));

        $this->assertEmpty($violations);
    }

    public function testCallbackAllowsEmptyValueWhenTokenAlreadyStored(): void
    {
        $constraint = HttpTokenConstraintFactory::tokenRequired(true);
        $integration = new Integration(true, [], InsightsApiConfig::NAME, [
            InsightsApiConfig::HTTP_TOKEN_FIELD => 'already-saved',
        ]);
        $violations = [];

        ($constraint->callback)('', $this->createContext($integration, $violations));

        $this->assertEmpty($violations);
    }

    public function testHasStoredHttpTokenReadsIntegrationEntityWhenFormDataEmpty(): void
    {
        $entity = new Integration(true, [], InsightsApiConfig::NAME, [
            InsightsApiConfig::HTTP_TOKEN_FIELD => 'stored-token',
        ]);
        $integration = $this->createMock(IntegrationInterface::class);
        $integration->method('hasIntegrationConfiguration')->willReturn(true);
        $integration->method('getIntegrationConfiguration')->willReturn($entity);

        $type = new AuthConfigType($this->createMock(TranslatorInterface::class));
        $method = new ReflectionMethod(AuthConfigType::class, 'hasStoredHttpToken');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($type, [
            'data' => [],
            'integration' => $integration,
        ]));
    }

    /**
     * @param list<string> $violations
     */
    private function createContext(Integration $integration, array &$violations): ExecutionContextInterface
    {
        $rootForm = new class($integration) {
            public function __construct(private Integration $integration)
            {
            }

            public function getData(): Integration
            {
                return $this->integration;
            }
        };

        $builder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $builder->method('addViolation')->willReturnCallback(static function () use (&$violations): void {
            $violations[] = 'required';
        });

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->method('getRoot')->willReturn($rootForm);
        $context->method('buildViolation')->willReturn($builder);

        return $context;
    }
}
