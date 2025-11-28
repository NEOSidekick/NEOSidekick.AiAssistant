<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250704150640 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MariaDb1027Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MariaDb1027Platform'."
        );

        $this->addSql('ALTER TABLE neosidekick_aiassistant_domain_model_automationsconfiguration ADD identifier VARCHAR(255) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX flow_identity_neosidekick_aiassistant_domain_model_automa_9a830 ON neosidekick_aiassistant_domain_model_automationsconfiguration (identifier)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MariaDb1027Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MariaDb1027Platform'."
        );

        $this->addSql('DROP INDEX flow_identity_neosidekick_aiassistant_domain_model_automa_9a830 ON neosidekick_aiassistant_domain_model_automationsconfiguration');
        $this->addSql('ALTER TABLE neosidekick_aiassistant_domain_model_automationsconfiguration DROP identifier');
    }
}
