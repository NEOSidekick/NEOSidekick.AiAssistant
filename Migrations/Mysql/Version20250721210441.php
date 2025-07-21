<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250721210441 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neosidekick_aiassistant_domain_model_automationsconfiguration DROP generateemptyimagealttextsonpublication, DROP generateemptyimagetitletextsonpublication, DROP includeneosidekickbriefinginprompt, DROP seoautomationsapprovalprocess, DROP imagealttextautomationsapprovalprocess, DROP brandguardapprovalprocess, DROP brandguardprompt');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neosidekick_aiassistant_domain_model_automationsconfiguration ADD generateemptyimagealttextsonpublication TINYINT(1) NOT NULL, ADD generateemptyimagetitletextsonpublication TINYINT(1) NOT NULL, ADD includeneosidekickbriefinginprompt TINYINT(1) NOT NULL, ADD seoautomationsapprovalprocess VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, ADD imagealttextautomationsapprovalprocess VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, ADD brandguardapprovalprocess VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, ADD brandguardprompt LONGTEXT CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`');
    }
}
