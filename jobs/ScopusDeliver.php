<?php

/**
 * @file plugins/generic/scopus/jobs/ScopusDeliver.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class ScopusDeliver
 *
 * @ingroup jobs
 *
 * @brief Build the XML and PDF documents and deliver them to the configured Scopus SFTP account.
 */

namespace APP\plugins\generic\scopus\jobs;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\scopus\ScopusExportPlugin;
use APP\plugins\PubObjectsExportPlugin;
use APP\publication\Publication;
use APP\submission\Submission;
use PKP\job\exceptions\JobException;
use PKP\jobs\BaseJob;
use PKP\plugins\PluginRegistry;
use Throwable;

class ScopusDeliver extends BaseJob
{
    public function __construct(
        protected int $objectId,
        protected bool $isPublication,
        protected int $contextId
    ) {
        parent::__construct();
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        /** @var Submission|Publication|null $object */
        $object = $this->isPublication
            ? Repo::publication()->get($this->objectId)
            : Repo::submission()->get($this->objectId);

        if (!$object) {
            throw new JobException(JobException::INVALID_PAYLOAD);
        }

        PluginRegistry::register('importexport', new ScopusExportPlugin(), 'plugins/generic/scopus/ScopusExportPlugin', $this->contextId);
        /** @var ScopusExportPlugin $plugin */
        $plugin = PluginRegistry::getPlugin('importexport', 'ScopusExportPlugin');

        if ($object->getData($plugin->getDepositStatusSettingName()) === PubObjectsExportPlugin::EXPORT_STATUS_REGISTERED) {
            return;
        }

        $context = Application::getContextDAO()->getById($this->contextId);
        $documents = $plugin->buildDocuments($object, $context);
        if (isset($documents['error'])) {
            $errorMessage = $plugin->convertErrorMessage($documents['error']);
            $plugin->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_ERROR, $errorMessage);
            throw new JobException($errorMessage);
        }

        try {
            $plugin->deliverToEndpoint($documents, $context);
            $plugin->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_REGISTERED);
        } catch (Throwable $e) {
            $plugin->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_ERROR, $e->getMessage());
            throw new JobException($e->getMessage());
        }
    }
}
