<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the agent refresh token record table backing the server-to-server JWT
 * renewal endpoint (NEOSidekick\AiAssistant\Domain\Model\AgentRefreshTokenRecord).
 */
final class Version20260817093606 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the NEOSidekick agent refresh token record table';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'mysql',
            'Migration can only be executed safely on MySQL and MariaDB.'
        );

        $this->addSql('CREATE TABLE neosidekick_aiassistant_domain_model_agentrefreshtokenrecord (persistence_object_identifier VARCHAR(40) NOT NULL, refreshtokenhash VARCHAR(64) NOT NULL, jti VARCHAR(40) NOT NULL, accountuuid VARCHAR(40) NOT NULL, familyid VARCHAR(40) NOT NULL, refreshexpiresat DATETIME NOT NULL, familyexpiresat DATETIME NOT NULL, revoked TINYINT(1) NOT NULL, consumermarker VARCHAR(64) DEFAULT \'chat\' NOT NULL, creationdatetime DATETIME NOT NULL, rotatedat DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_3B1904F7DD8092C9 (refreshtokenhash), UNIQUE INDEX uniq_nsk_agentrefresh_jti (jti), INDEX idx_nsk_agentrefresh_familyid (familyid), INDEX idx_nsk_agentrefresh_accountuuid (accountuuid), PRIMARY KEY(persistence_object_identifier)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'mysql',
            'Migration can only be executed safely on MySQL and MariaDB.'
        );

        $this->addSql('DROP TABLE neosidekick_aiassistant_domain_model_agentrefreshtokenrecord');
    }
}
