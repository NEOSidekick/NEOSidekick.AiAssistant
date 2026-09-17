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
final class Version20260903150853 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the NEOSidekick agent signing key record table';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'mysql',
            'Migration can only be executed safely on MySQL and MariaDB.'
        );

        $this->addSql('CREATE TABLE neosidekick_aiassistant_domain_model_agentsigningkeyrecord (persistence_object_identifier VARCHAR(40) NOT NULL, slot VARCHAR(16) NOT NULL, privatekeypem LONGTEXT NOT NULL, publickeypem LONGTEXT NOT NULL, pendingprivatekeypem LONGTEXT DEFAULT NULL, pendingpublickeypem LONGTEXT DEFAULT NULL, pendingkid VARCHAR(64) DEFAULT NULL, pendingrelabel TINYINT(1) NOT NULL, pendingretriedat DATETIME DEFAULT NULL, pushkid VARCHAR(64) DEFAULT NULL, pushtarget VARCHAR(255) DEFAULT NULL, pushstatus VARCHAR(64) DEFAULT NULL, pushpluginversion VARCHAR(32) DEFAULT NULL, pushedat DATETIME DEFAULT NULL, pushinstallrootkid VARCHAR(64) DEFAULT NULL, pushreenrolled TINYINT(1) DEFAULT NULL, pushreenrolledat DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_D562B9F7AC0E2067 (slot), PRIMARY KEY(persistence_object_identifier)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'mysql',
            'Migration can only be executed safely on MySQL and MariaDB.'
        );

        $this->addSql('DROP TABLE neosidekick_aiassistant_domain_model_agentsigningkeyrecord');
    }
}
