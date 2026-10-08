<?php

declare(strict_types=1);

namespace Mautic\ApiBundle\Tests\Functional;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\CoreBundle\Test\MauticMysqlTestCase;

/**
 * Contract test: every Mautic permission referenced by an API v2 security
 * expression must actually be declared by a permission class.
 *
 * CorePermissions::isGranted() throws PermissionNotFoundException for an
 * unknown permission, so a typo in a security expression turns every
 * non-admin request to that operation into a 500 error. Admins short-circuit
 * the lookup and are granted everything, which hides the mistake in manual
 * testing.
 */
final class SecurityExpressionPermissionMetadataTest extends MauticMysqlTestCase
{
    public function testAllSecurityExpressionsReferenceExistingPermissions(): void
    {
        $nameFactory     = self::getContainer()->get(ResourceNameCollectionFactoryInterface::class);
        $metadataFactory = self::getContainer()->get(ResourceMetadataCollectionFactoryInterface::class);
        $security        = self::getContainer()->get(CorePermissions::class);

        $violations = [];

        foreach ($nameFactory->create() as $resourceClass) {
            foreach ($metadataFactory->create($resourceClass) as $resourceMetadata) {
                foreach ($resourceMetadata->getOperations() as $operationName => $operation) {
                    if (!$operation instanceof HttpOperation) {
                        continue;
                    }

                    $expression = $operation->getSecurity();

                    if (null === $expression) {
                        continue;
                    }

                    foreach ($this->extractPermissions($expression) as $permission) {
                        if (!$security->checkPermissionExists($permission)) {
                            $violations[] = sprintf(
                                '  %s [%s]: %s',
                                $resourceClass,
                                $operationName,
                                $permission
                            );
                        }
                    }
                }
            }
        }

        $this->assertEmpty($violations, "The following API v2 security expressions reference permissions that no permission class declares:\n"
        .implode("\n", $violations));
    }

    /**
     * @return string[]
     */
    private function extractPermissions(string $expression): array
    {
        // Symfony attributes such as ROLE_ADMIN and IS_AUTHENTICATED_FULLY carry no
        // colon and are skipped by the bundle:level:permission shape below.
        preg_match_all('/is_granted\(\s*\'([a-z0-9_]+(?::[a-z0-9_]+){2,3})\'/i', $expression, $matches);

        $permissions = [];

        foreach ($matches[1] as $permission) {
            // CorePermissions::isGranted() accepts a fourth segment but resolves the
            // permission from the first three, so compare on the same three.
            $permissions[] = implode(':', array_slice(explode(':', $permission), 0, 3));
        }

        return $permissions;
    }
}
