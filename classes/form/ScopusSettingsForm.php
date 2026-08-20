<?php

/**
 * @file plugins/generic/scopus/classes/form/ScopusSettingsForm.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class ScopusSettingsForm
 *
 * @brief Form for journal managers to configure Scopus delivery
 */

namespace APP\plugins\generic\scopus\classes\form;

use APP\plugins\generic\scopus\ScopusExportPlugin;
use APP\plugins\PubObjectsExportSettingsForm;
use PKP\form\validation\FormValidator;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorCustom;
use PKP\form\validation\FormValidatorPost;

class ScopusSettingsForm extends PubObjectsExportSettingsForm
{
    /**
     * Constructor
     */
    public function __construct(private readonly ScopusExportPlugin $plugin, private readonly int $contextId)
    {
        parent::__construct($this->plugin->getTemplateResource('settingsForm.tpl'));

        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
        // The SFTP account is optional (Export-only use is valid), but partially
        // filling it in is not -- either all of host/username/password, or none.
        $this->addCheck(
            new FormValidatorCustom(
                $this,
                'host',
                FormValidator::FORM_VALIDATOR_OPTIONAL_VALUE,
                'plugins.importexport.scopus.settings.form.accountIncomplete',
                fn () => $this->plugin->isSftpAccountEmpty($this->getSftpAccountData()) || $this->plugin->isSftpAccountComplete($this->getSftpAccountData())
            )
        );
        $this->addCheck(
            new FormValidatorCustom(
                $this,
                'automaticRegistration',
                FormValidator::FORM_VALIDATOR_OPTIONAL_VALUE,
                'plugins.importexport.scopus.settings.form.automaticRegistrationRequiresAccount',
                fn () => $this->plugin->isSftpAccountComplete($this->getSftpAccountData())
            )
        );
    }

    /**
     * The submitted (not yet saved) host/username/password.
     */
    protected function getSftpAccountData(): array
    {
        return [
            'host' => $this->getData('host'),
            'username' => $this->getData('username'),
            'password' => $this->getData('password'),
        ];
    }

    /**
     * @copydoc Form::initData()
     */
    public function initData(): void
    {
        foreach ($this->getFormFields() as $fieldName => $fieldType) {
            $this->setData($fieldName, $this->plugin->getSetting($this->contextId, $fieldName));
        }
    }

    /**
     * @copydoc Form::readInputData()
     */
    public function readInputData(): void
    {
        $this->readUserVars(array_keys($this->getFormFields()));
    }

    /**
     * @copydoc Form::execute()
     */
    public function execute(...$functionArgs): void
    {
        parent::execute(...$functionArgs);
        foreach ($this->getFormFields() as $fieldName => $fieldType) {
            $this->plugin->updateSetting($this->contextId, $fieldName, $this->getData($fieldName), $fieldType);
        }
    }

    /**
     * @copydoc PubObjectsExportSettingsForm::getFormFields()
     */
    public function getFormFields(): array
    {
        return [
            'host' => 'string',
            'port' => 'string',
            'path' => 'string',
            'username' => 'string',
            'password' => 'string',
            'automaticRegistration' => 'bool',
        ];
    }

    /**
     * @copydoc PubObjectsExportSettingsForm::isOptional()
     */
    public function isOptional(string $settingName): bool
    {
        return in_array($settingName, ['host', 'port', 'path', 'username', 'password', 'automaticRegistration']);
    }
}
