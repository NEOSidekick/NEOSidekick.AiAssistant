<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the domain the NEOSidekick backend has this installation's lineage registered under,
 * echoed on every successful push, so the module can warn a copy of another installation
 * before its administrator guesses (NEOSidekick\AiAssistant\Domain\Model\AgentSigningKeyRecord).
 */
final class Version20260904111501 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the registered domain echoed by the NEOSidekick signing key push';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'mysql',
            'Migration can only be executed safely on MySQL and MariaDB.'
        );

        $this->addSql('ALTER TABLE neosidekick_aiassistant_domain_model_agentsigningkeyrecord ADD pushregistereddomain VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'mysql',
            'Migration can only be executed safely on MySQL and MariaDB.'
        );

        $this->addSql('ALTER TABLE neosidekick_aiassistant_domain_model_agentsigningkeyrecord DROP pushregistereddomain');
    }
}
