<?php

/**
 * @file plugins/generic/scopus/ScopusExportPlugin.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class ScopusExportPlugin
 *
 * @brief Scopus export plugin
 */

namespace APP\plugins\generic\scopus;

use APP\facades\Repo;
use APP\plugins\generic\scopus\jobs\ScopusDeliver;
use APP\plugins\PubObjectsExportPlugin;
use APP\publication\Publication;
use APP\submission\Submission;
use APP\template\TemplateManager;
use League\Flysystem\Filesystem;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use PKP\context\Context;
use PKP\db\DAORegistry;
use PKP\file\FileManager;
use PKP\notification\Notification;
use PKP\plugins\interfaces\HasTaskScheduler;
use PKP\publication\PKPPublication;
use PKP\scheduledTask\PKPScheduler;
use PKP\submission\Genre;
use PKP\submission\GenreDAO;
use PKP\submissionFile\SubmissionFile;
use ZipArchive;

class ScopusExportPlugin extends PubObjectsExportPlugin implements HasTaskScheduler
{
    /**
     * @copydoc ImportExportPlugin::display()
     */
    public function display($args, $request): void
    {
        parent::display($args, $request);
        $templateManager = TemplateManager::getManager();
        $templateManager->assign([
            'sftpLibraryMissing' => !class_exists('\League\Flysystem\PhpseclibV3\SftpAdapter'),
        ]);

        switch (array_shift($args)) {
            case 'index':
            case '':
                $templateMgr = TemplateManager::getManager($request);
                $templateMgr->display($this->getTemplateResource('index.tpl'));
                break;
        }
    }

    /**
     * @copydoc Plugin::getName()
     */
    public function getName(): string
    {
        return 'ScopusExportPlugin';
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName(): string
    {
        return __('plugins.importexport.scopus.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription(): string
    {
        return __('plugins.importexport.scopus.description.short');
    }

    /**
     * @copydoc ImportExportPlugin::getPluginSettingsPrefix()
     */
    public function getPluginSettingsPrefix(): string
    {
        return 'scopus';
    }

    /**
     * @copydoc Plugin::getEncryptedSettingFields()
     */
    public function getEncryptedSettingFields(): array
    {
        return [
            'password',
        ];
    }

    /**
     * @copydoc PubObjectsExportPlugin::getExportDeploymentClassName()
     */
    public function getExportDeploymentClassName(): string
    {
        return '\APP\plugins\generic\scopus\ScopusExportDeployment';
    }

    /**
     * @copydoc PubObjectsExportPlugin::getSettingsFormClassName()
     */
    public function getSettingsFormClassName(): string
    {
        return '\APP\plugins\generic\scopus\classes\form\ScopusSettingsForm';
    }

    /**
     * @copydoc PubObjectsExportPlugin::getDepositSuccessNotificationMessageKey()
     */
    public function getDepositSuccessNotificationMessageKey()
    {
        return 'plugins.importexport.scopus.submit.success';
    }

    /**
     * @copydoc \PKP\plugins\interfaces\HasTaskScheduler::registerSchedules()
     */
    public function registerSchedules(PKPScheduler $scheduler): void
    {
        $scheduler
            ->addSchedule(new ScopusInfoSender())
            ->daily()
            ->name(ScopusInfoSender::class)
            ->withoutOverlapping();
    }

    /**
     * @copydoc PubObjectsExportPlugin::getExportActions()
     */
    public function getExportActions($context): array
    {
        $actions = [PubObjectsExportPlugin::EXPORT_ACTION_EXPORT, PubObjectsExportPlugin::EXPORT_ACTION_MARKREGISTERED];
        if ($this->hasCompleteSettings($context->getId())) {
            array_unshift($actions, PubObjectsExportPlugin::EXPORT_ACTION_DEPOSIT);
        }
        return $actions;
    }

    /**
     * Whether the configured SFTP account has everything required to deliver to it.
     */
    protected function hasCompleteSettings(int $contextId): bool
    {
        return $this->isSftpAccountComplete([
            'host' => $this->getSetting($contextId, 'host'),
            'username' => $this->getSetting($contextId, 'username'),
            'password' => $this->getSetting($contextId, 'password'),
        ]);
    }

    /**
     * Whether an SFTP account (host/username/password) is fully filled in. The
     * account is optional -- a journal may use the plugin for Export only and
     * deliver to Scopus manually outside OJS -- but if any field is set, all must be.
     */
    public function isSftpAccountComplete(array $account): bool
    {
        return !empty($account['host']) && !empty($account['username']) && !empty($account['password']);
    }

    /**
     * Whether none of the SFTP account fields are set.
     */
    public function isSftpAccountEmpty(array $account): bool
    {
        return empty($account['host']) && empty($account['username']) && empty($account['password']);
    }

    /**
     * @copydoc PubObjectsExportPlugin::executeExportAction()
     */
    public function executeExportAction($request, $objects, $filter, $tab, $objectsFileNamePart, $noValidation = null, $shouldRedirect = true): void
    {
        $context = $request->getContext();
        $path = ['plugin', $this->getName()];

        if ($request->getUserVar(PubObjectsExportPlugin::EXPORT_ACTION_DEPOSIT)) {
            $result = $this->depositXML($objects, $context, null);
            if ($result === true) {
                $this->_sendNotification(
                    $request->getUser(),
                    $this->getDepositSuccessNotificationMessageKey(),
                    Notification::NOTIFICATION_TYPE_SUCCESS
                );
            } else {
                foreach ((array) $result as $error) {
                    $this->_sendNotification(
                        $request->getUser(),
                        $error[0],
                        Notification::NOTIFICATION_TYPE_ERROR,
                        ($error[1] ?? null)
                    );
                }
            }
            $request->redirect(null, null, null, $path, null, $tab);
        } elseif ($request->getUserVar(PubObjectsExportPlugin::EXPORT_ACTION_EXPORT)) {
            // Always zip for download, even a single object -- delivery keeps the two
            // files unzipped (per Scopus's own spec), but a browser response can only
            // carry one file, so the download path needs a container either way.
            $fileManager = new FileManager();
            $result = $this->createZipCollection($objects, $context);
            if (!empty($result['error'])) {
                $this->_sendNotification(
                    $request->getUser(),
                    $result['error'][0],
                    Notification::NOTIFICATION_TYPE_ERROR,
                    ($result['error'][1] ?? null)
                );
                $request->redirect(null, null, null, $path, null, $tab);
                return;
            }
            if (count($objects) === 1) {
                $object = reset($objects);
                $filename = $this->buildFileName($object, $context) . '.zip';
            } else {
                $filename = $this->buildAcronym($context) . '_export_' . date('Y-m-d-H-i-s') . '.zip';
            }
            $fileManager->downloadByPath($result['path'], 'application/zip', false, $filename);
            $fileManager->deleteByPath($result['path']);
        } else {
            parent::executeExportAction($request, $objects, $filter, $tab, $objectsFileNamePart, $noValidation, $shouldRedirect);
        }
    }

    /**
     * Dispatches a job per selected object to build and deliver its documents, so
     * the SFTP upload can't block the triggering request.
     *
     * @copydoc PubObjectsExportPlugin::depositXML()
     *
     * @param Submission[]|Publication[] $objects
     * @param null|mixed $filename
     *
     * @return bool|array True on success (i.e. successfully queued), or an array of error messages.
     */
    public function depositXML($objects, $context, $filename = null): bool|array
    {
        if (!$this->hasCompleteSettings($context->getId())) {
            return [['plugins.importexport.scopus.export.failure.settings']];
        }

        $objects = $this->includePreviousUnregisteredVersions($objects);

        foreach ($objects as $object) {
            dispatch(new ScopusDeliver(
                $object->getId(),
                $object instanceof Publication,
                $context->getId()
            ));
            $this->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_SUBMITTED);
        }

        return true;
    }

    /**
     * Include any not-yet-registered earlier version of the same submission.
     *
     * @param Submission[]|Publication[] $objects
     *
     * @return Submission[]|Publication[]
     */
    protected function includePreviousUnregisteredVersions(array $objects): array
    {
        $expanded = $objects;
        $includedIds = [];
        foreach ($objects as $object) {
            if ($object instanceof Publication) {
                $includedIds[$object->getId()] = true;
            }
        }

        foreach ($objects as $object) {
            if (!$object instanceof Publication) {
                continue;
            }
            foreach ($this->findUnregisteredEarlierVersions($object) as $earlierVersion) {
                if (isset($includedIds[$earlierVersion->getId()])) {
                    continue;
                }
                $expanded[] = $earlierVersion;
                $includedIds[$earlierVersion->getId()] = true;
            }
        }

        return $expanded;
    }

    /**
     * Find the latest published minor of every (stage, major) earlier than the given
     * VoR publication's own, for the same submission, that hasn't been registered yet.
     *
     * @return Publication[]
     */
    protected function findUnregisteredEarlierVersions(Publication $vorPublication): array
    {
        $publications = Repo::publication()->getCollector()
            ->filterBySubmissionIds([$vorPublication->getData('submissionId')])
            ->filterByStatus([PKPPublication::STATUS_PUBLISHED])
            ->orderByVersion()
            ->getMany();

        $latestByStageMajor = [];
        foreach ($publications as $publication) {
            if ($publication->getId() === $vorPublication->getId()) {
                break;
            }
            $key = $publication->getData('versionStage') . '-' . $publication->getData('versionMajor');
            $latestByStageMajor[$key] = $publication;
        }

        return array_values(array_filter(
            $latestByStageMajor,
            fn (Publication $publication) => $publication->getData($this->getDepositStatusSettingName()) !== PubObjectsExportPlugin::EXPORT_STATUS_REGISTERED
        ));
    }

    /**
     * Write both documents to the configured SFTP account, in one connection, unzipped.
     *
     * @param array{xmlContent: string, xmlFilename: string, pdfContent: string, pdfFilename: string} $documents
     */
    public function deliverToEndpoint(array $documents, Context $context): void
    {
        $adapter = new SftpAdapter(
            new SftpConnectionProvider(
                host: $this->getSetting($context->getId(), 'host'),
                username: $this->getSetting($context->getId(), 'username'),
                password: $this->getSetting($context->getId(), 'password'),
                port: ((int) $this->getSetting($context->getId(), 'port')) ?: 22,
            ),
            $this->getSetting($context->getId(), 'path') ?: '/',
        );
        $fs = new Filesystem($adapter);

        $fs->write($documents['xmlFilename'], $documents['xmlContent']);
        $fs->write($documents['pdfFilename'], $documents['pdfContent']);
    }

    /**
     * Build the uploaded JATS full-text document and primary PDF galley for delivery,
     * both delivered as-is.
     *
     * @return array{xmlContent: string, xmlFilename: string, pdfContent: string, pdfFilename: string}|array{error: array}
     */
    public function buildDocuments(Submission|Publication $object, Context $context): array
    {
        $publication = $object instanceof Publication ? $object : $object->getCurrentPublication();
        $submissionId = $object instanceof Publication ? $object->getData('submissionId') : $object->getId();

        $label = $submissionId . ' ' . $publication->getData('versionStage') . $publication->getData('versionMajor');

        // A journal using article-number naming must supply one for every article --
        // falling back to the internal ID here would risk colliding with another
        // article's own article number.
        if ($context->getData('enableArticleNumber') && empty($publication->getData('articleNumber'))) {
            return ['error' => ['plugins.importexport.scopus.export.failure.missingArticleNumber', $label]];
        }

        /** @var GenreDAO $genreDao */
        $genreDao = DAORegistry::getDAO('GenreDAO');
        $genres = [];
        foreach ($genreDao->getEnabledByContextId($context->getId())->toArray() as $genre) {
            $genres[$genre->getId()] = $genre;
        }

        // getJatsFile() ignores jatsPublicVisibility -- that only gates the reader-facing
        // public download, unrelated to whether this content is archived externally.
        $jatsFile = Repo::jats()->getJatsFile($publication->getId(), $submissionId, array_values($genres));
        if ($jatsFile->isDefaultContent || !$jatsFile->jatsContent) {
            return ['error' => ['plugins.importexport.scopus.export.failure.noUploadedJats', $label]];
        }

        $pdfFiles = $this->findPrimaryPdfFiles($publication, $genres);
        if (count($pdfFiles) === 0) {
            return ['error' => ['plugins.importexport.scopus.export.failure.noPdf', $label]];
        }
        if (count($pdfFiles) > 1) {
            return ['error' => ['plugins.importexport.scopus.export.failure.multiplePdf', $label]];
        }

        $fileName = $this->buildFileName($object, $context);
        $fileService = app()->get('file');
        $pdfPath = $fileService->get($pdfFiles[0]->getData('fileId'))->path;

        return [
            'xmlContent' => $jatsFile->jatsContent,
            'xmlFilename' => $fileName . '.xml',
            'pdfContent' => $fileService->fs->read($pdfPath),
            'pdfFilename' => $fileName . '.pdf',
        ];
    }

    /**
     * Find the publication's own primary-document PDF galley file(s), restricted to
     * the publication's own locale -- Scopus expects exactly one PDF per version, not
     * one per locale.
     *
     * @param array<int, Genre> $genres Enabled genres for the context, keyed by ID
     *
     * @return SubmissionFile[]
     */
    protected function findPrimaryPdfFiles(Publication $publication, array $genres): array
    {
        $locale = $publication->getData('locale');
        $matches = [];
        foreach ($publication->getData('galleys') ?? [] as $galley) {
            if ($galley->getData('urlRemote') || !$galley->getData('submissionFileId')) {
                continue;
            }
            if ($galley->getData('locale') !== $locale) {
                continue;
            }
            $submissionFile = Repo::submissionFile()->get($galley->getData('submissionFileId'));
            if (!$submissionFile || $submissionFile->getData('mimetype') !== 'application/pdf') {
                continue;
            }
            $genre = $genres[$submissionFile->getData('genreId')] ?? null;
            if (!$genre || $genre->getCategory() !== Genre::GENRE_CATEGORY_DOCUMENT || $genre->getSupplementary() || $genre->getDependent()) {
                continue;
            }
            $matches[] = $submissionFile;
        }
        return $matches;
    }

    /**
     * Build the delivered documents' filename (without extension): {acronym}_{articleId}_{versionStage}{versionMajor}.
     */
    public function buildFileName(Submission|Publication $object, Context $context): string
    {
        $publication = $object instanceof Publication ? $object : $object->getCurrentPublication();
        $fallbackId = $object instanceof Submission ? $object->getId() : $object->getData('submissionId');
        $articleId = ($context->getData('enableArticleNumber') ? $publication->getData('articleNumber') : null) ?: $fallbackId;

        return $this->buildAcronym($context) . '_' . $this->sanitizeForFileName((string) $articleId) . '_' . $publication->getData('versionStage') . $publication->getData('versionMajor');
    }

    /**
     * Replace characters unsafe for a filename with underscores, collapsing runs into one --
     * e.g. a migrated article number like "1:137" becomes "1_137", not "1137" (which would
     * lose the separator and could then collide with another article literally numbered 1137).
     */
    protected function sanitizeForFileName(string $value): string
    {
        return preg_replace('/[^a-zA-Z0-9\-]+/', '_', $value);
    }

    /**
     * Bundle multiple objects' documents (XML + PDF each) into a single ZIP for download.
     *
     * @param Submission[]|Publication[] $objects
     *
     * @return array{path: string}|array{error: array}
     */
    protected function createZipCollection(array $objects, Context $context): array
    {
        $zipPath = $this->createTempPath();
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
            @unlink($zipPath);
            return ['error' => ['plugins.importexport.scopus.export.failure.creatingCollectionFile']];
        }

        foreach ($objects as $object) {
            $documents = $this->buildDocuments($object, $context);
            if (isset($documents['error'])) {
                $zip->close();
                @unlink($zipPath);
                return ['error' => $documents['error']];
            }
            if (!$zip->addFromString($documents['xmlFilename'], $documents['xmlContent'])
                || !$zip->addFromString($documents['pdfFilename'], $documents['pdfContent'])) {
                $zip->close();
                @unlink($zipPath);
                return ['error' => ['plugins.importexport.scopus.export.failure.creatingCollectionFile']];
            }
        }
        $zip->close();

        return ['path' => $zipPath];
    }

    /**
     * Create an empty temp file, under files_dir/temp/.
     */
    protected function createTempPath(): string
    {
        $exportPath = $this->getExportPath();
        (new FileManager())->mkdirtree($exportPath);
        return tempnam($exportPath, 'ScopusExport_');
    }

    /**
     * Build the journal acronym component used for the outer export ZIP's filename.
     */
    protected function buildAcronym(Context $context): string
    {
        $locale = $context->getData('primaryLocale');
        $acronym = $context->getData('acronym', $locale) ?: $context->getPath();
        return preg_replace('/[^a-zA-Z0-9]/', '', $acronym);
    }

    /**
     * Helper to convert an error array to a translated string.
     */
    public function convertErrorMessage(array $errorMessage): string
    {
        return __($errorMessage[0], ['param' => $errorMessage[1] ?? null]);
    }

    /**
     * @copydoc ImportExportPlugin::executeCLI()
     */
    public function executeCLI($scriptName, &$args)
    {
    }

    /**
     * @copydoc ImportExportPlugin::usage()
     */
    public function usage($scriptName)
    {
    }
}
