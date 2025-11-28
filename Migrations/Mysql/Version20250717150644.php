<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250717150644 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX flow_identity_neosidekick_aiassistant_domain_model_automa_9a830 ON neosidekick_aiassistant_domain_model_automationsconfiguration');
        $this->addSql('ALTER TABLE neosidekick_aiassistant_domain_model_automationsconfiguration ADD site VARCHAR(40) DEFAULT NULL, DROP identifier');
        $this->addSql('ALTER TABLE neosidekick_aiassistant_domain_model_automationsconfiguration ADD CONSTRAINT FK_8AEC8DA3694309E4 FOREIGN KEY (site) REFERENCES neos_neos_domain_model_site (persistence_object_identifier)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8AEC8DA3694309E4 ON neosidekick_aiassistant_domain_model_automationsconfiguration (site)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neosidekick_aiassistant_domain_model_automationsconfiguration DROP FOREIGN KEY FK_8AEC8DA3694309E4');
        $this->addSql('DROP INDEX UNIQ_8AEC8DA3694309E4 ON neosidekick_aiassistant_domain_model_automationsconfiguration');
        $this->addSql('ALTER TABLE neosidekick_aiassistant_domain_model_automationsconfiguration ADD identifier VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, DROP site');
        $this->addSql('CREATE UNIQUE INDEX flow_identity_neosidekick_aiassistant_domain_model_automa_9a830 ON neosidekick_aiassistant_domain_model_automationsconfiguration (identifier)');
    }
}
