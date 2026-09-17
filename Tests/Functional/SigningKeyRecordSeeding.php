<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use NEOSidekick\AiAssistant\Domain\Model\AgentSigningKeyRecord;
use NEOSidekick\AiAssistant\Domain\Repository\AgentSigningKeyRecordRepository;
use NEOSidekick\AiAssistant\Service\AgentKeyPairService;
use ReflectionClass;
use ReflectionProperty;

/**
 * Gives every functional suite that needs a signing key the one row the installation's key
 * lives in - written through the real repository, so the suites exercise the same storage
 * the production code reads.
 *
 * A trait rather than a base-class method: these suites extend Flow's FunctionalTestCase
 * directly (they need neither sites nor content), so there is no shared plugin base class
 * to hang it on.
 *
 * Both operations also reset the private per-request state of the singleton
 * {@see AgentKeyPairService}: it memoizes the row for the request, and a functional test
 * process outlives many "requests". Without the reset a suite would keep serving the
 * previous test's key - or keep reporting a key after this test deleted the row.
 */
trait SigningKeyRecordSeeding
{
    private const SIGNING_KEY_RECORD_TABLE = 'neosidekick_aiassistant_domain_model_agentsigningkeyrecord';

    /**
     * Writes the installation's signing-key row, replacing whatever was there. Defaults to the
     * checked-in fixture keypair, so the kid and the fingerprint stay the pinned ones.
     *
     * @param string|null $privatePem The live private key PEM, or null for the fixture one
     * @param string|null $publicPem The matching public key PEM, or null for the fixture one
     */
    protected function seedSigningKeyRecord(?string $privatePem = null, ?string $publicPem = null): AgentSigningKeyRecord
    {
        $this->clearSigningKeyRecord();

        $repository = $this->objectManager->get(AgentSigningKeyRecordRepository::class);
        $repository->insertIfAbsent(new AgentSigningKeyRecord(
            $privatePem ?? $this->fixtureSigningKeyPem('agent-test-signing-key.pem'),
            $publicPem ?? $this->fixtureSigningKeyPem('agent-test-signing-key.pub.pem')
        ));

        $record = $repository->findInstallRecord();
        self::assertInstanceOf(AgentSigningKeyRecord::class, $record, 'the seeded signing-key row must load back');

        return $record;
    }

    /**
     * Turns the installation keyless again - the state a deleted row or a restore predating the
     * key leaves behind, and what tests of the generation triggers start from.
     */
    protected function clearSigningKeyRecord(): void
    {
        $this->objectManager->get(EntityManagerInterface::class)->getConnection()->executeStatement(
            'DELETE FROM ' . self::SIGNING_KEY_RECORD_TABLE
        );
        $this->resetSigningKeyServiceState();
    }

    protected function countSigningKeyRecords(): int
    {
        return (int)$this->objectManager->get(EntityManagerInterface::class)->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM ' . self::SIGNING_KEY_RECORD_TABLE
        );
    }

    /**
     * Reads one column of the install row past the identity map, the way the compare-and-swap
     * updates write it.
     */
    protected function readSigningKeyColumn(string $columnName): ?string
    {
        $value = $this->objectManager->get(EntityManagerInterface::class)->getConnection()->fetchOne(
            'SELECT ' . $columnName . ' FROM ' . self::SIGNING_KEY_RECORD_TABLE . ' WHERE slot = ?',
            [AgentSigningKeyRecord::SLOT_INSTALL]
        );

        return $value === null || $value === false ? null : (string)$value;
    }

    /**
     * @return array{0: string, 1: string} A fresh private and public key PEM, generated exactly
     *                                     like the production key
     */
    protected function generateSigningKeyPair(): array
    {
        $keyResource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'digest_alg' => 'sha256',
        ]);
        self::assertNotFalse($keyResource, 'the test needs a real RSA keypair');

        $privateKeyPem = '';
        self::assertTrue(openssl_pkey_export($keyResource, $privateKeyPem));
        $keyDetails = openssl_pkey_get_details($keyResource);
        self::assertIsArray($keyDetails);

        return [$privateKeyPem, (string)$keyDetails['key']];
    }

    private function fixtureSigningKeyPem(string $fixtureFilename): string
    {
        return (string)file_get_contents(__DIR__ . '/../Fixtures/' . $fixtureFilename);
    }

    /**
     * Swaps the repository the signing key is loaded through, handing back the previous value so
     * the test can restore it. The service's memoized row is dropped with it, otherwise the next
     * load would be answered from the identity map instead of the double.
     */
    protected function replaceSigningKeyRecordRepository(object $recordRepository): object
    {
        $keyPairService = $this->objectManager->get(AgentKeyPairService::class);
        $property = new ReflectionProperty(AgentKeyPairService::class, 'agentSigningKeyRecordRepository');
        $property->setAccessible(true);
        $previousValue = $property->getValue($keyPairService);
        $property->setValue($keyPairService, $recordRepository);
        $this->resetSigningKeyServiceState();

        return $previousValue;
    }

    /**
     * Drops the memoized row, the "the table is missing" answer of the last load and the
     * once-per-request missing-table warning of the singleton service.
     */
    private function resetSigningKeyServiceState(): void
    {
        $keyPairService = $this->objectManager->get(AgentKeyPairService::class);
        foreach (['record' => null, 'storageMissingTable' => false, 'missingTableLogged' => false] as $propertyName => $value) {
            $property = $this->declaredProperty($keyPairService, $propertyName);
            $property->setAccessible(true);
            $property->setValue($keyPairService, $value);
        }
    }

    /**
     * The three properties above are PRIVATE, and Flow's proxy is a SUBCLASS of the original
     * class - a private property is therefore not declared on the class the instance reports.
     * The declaring class is found by walking up instead.
     */
    private function declaredProperty(object $instance, string $propertyName): ReflectionProperty
    {
        for ($class = new ReflectionClass($instance); $class !== false; $class = $class->getParentClass()) {
            if ($class->hasProperty($propertyName)) {
                return $class->getProperty($propertyName);
            }
        }

        self::fail(sprintf('%s declares no property $%s', get_class($instance), $propertyName));
    }
}
