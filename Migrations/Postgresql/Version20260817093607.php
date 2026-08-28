<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the agent refresh token record table backing the server-to-server JWT
 * renewal endpoint (NEOSidekick\AiAssistant\Domain\Model\AgentRefreshTokenRecord).
 */
final class Version20260817093607 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the NEOSidekick agent refresh token record table';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'postgresql',
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql('CREATE TABLE neosidekick_aiassistant_domain_model_agentrefreshtokenrecord (persistence_object_identifier VARCHAR(40) NOT NULL, refreshtokenhash VARCHAR(64) NOT NULL, jti VARCHAR(40) NOT NULL, accountuuid VARCHAR(40) NOT NULL, familyid VARCHAR(40) NOT NULL, refreshexpiresat TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, familyexpiresat TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, revoked BOOLEAN NOT NULL, consumermarker VARCHAR(64) DEFAULT \'chat\' NOT NULL, creationdatetime TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, rotatedat TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(persistence_object_identifier))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3B1904F7DD8092C9 ON neosidekick_aiassistant_domain_model_agentrefreshtokenrecord (refreshtokenhash)');
        $this->addSql('CREATE UNIQUE INDEX uniq_nsk_agentrefresh_jti ON neosidekick_aiassistant_domain_model_agentrefreshtokenrecord (jti)');
        $this->addSql('CREATE INDEX idx_nsk_agentrefresh_familyid ON neosidekick_aiassistant_domain_model_agentrefreshtokenrecord (familyid)');
        $this->addSql('CREATE INDEX idx_nsk_agentrefresh_accountuuid ON neosidekick_aiassistant_domain_model_agentrefreshtokenrecord (accountuuid)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'postgresql',
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql('DROP TABLE neosidekick_aiassistant_domain_model_agentrefreshtokenrecord');
    }
}
