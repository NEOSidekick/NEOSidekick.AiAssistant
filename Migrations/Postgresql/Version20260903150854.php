<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the agent signing key record table holding the installation's RSA signing
 * keypair, its pending rotation successor and the state of the last push to the
 * NEOSidekick backend (NEOSidekick\AiAssistant\Domain\Model\AgentSigningKeyRecord).
 */
final class Version20260903150854 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the NEOSidekick agent signing key record table';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'postgresql',
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql('CREATE TABLE neosidekick_aiassistant_domain_model_agentsigningkeyrecord (persistence_object_identifier VARCHAR(40) NOT NULL, slot VARCHAR(16) NOT NULL, privatekeypem TEXT NOT NULL, publickeypem TEXT NOT NULL, pendingprivatekeypem TEXT DEFAULT NULL, pendingpublickeypem TEXT DEFAULT NULL, pendingkid VARCHAR(64) DEFAULT NULL, pendingrelabel BOOLEAN NOT NULL, pendingretriedat TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, pushkid VARCHAR(64) DEFAULT NULL, pushtarget VARCHAR(255) DEFAULT NULL, pushstatus VARCHAR(64) DEFAULT NULL, pushpluginversion VARCHAR(32) DEFAULT NULL, pushedat TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, pushinstallrootkid VARCHAR(64) DEFAULT NULL, pushreenrolled BOOLEAN DEFAULT NULL, pushreenrolledat TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(persistence_object_identifier))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D562B9F7AC0E2067 ON neosidekick_aiassistant_domain_model_agentsigningkeyrecord (slot)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'postgresql',
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql('DROP TABLE neosidekick_aiassistant_domain_model_agentsigningkeyrecord');
    }
}
