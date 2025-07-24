<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250704141537 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE neosidekick_aiassistant_domain_model_automationsconfiguration (persistence_object_identifier VARCHAR(40) NOT NULL, determinemissingfocuskeywordsonpublication TINYINT(1) NOT NULL, redetermineexistingfocuskeywordsonpublication TINYINT(1) NOT NULL, generateemptyseotitlesonpublication TINYINT(1) NOT NULL, generateemptymetadescriptionsonpublication TINYINT(1) NOT NULL, regenerateexistingseotitlesonpublication TINYINT(1) NOT NULL, regenerateexistingmetadescriptionsonpublication TINYINT(1) NOT NULL, generateemptyimagealttextsonpublication TINYINT(1) NOT NULL, generateemptyimagetitletextsonpublication TINYINT(1) NOT NULL, includeneosidekickbriefinginprompt TINYINT(1) NOT NULL, seoautomationsapprovalprocess VARCHAR(255) NOT NULL, imagealttextautomationsapprovalprocess VARCHAR(255) NOT NULL, brandguardapprovalprocess VARCHAR(255) NOT NULL, PRIMARY KEY(persistence_object_identifier)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE neosidekick_aiassistant_domain_model_automationsconfiguration');
    }
}
