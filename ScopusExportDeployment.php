<?php

/**
 * @file plugins/generic/scopus/ScopusExportDeployment.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class ScopusExportDeployment
 *
 * @brief Unused stub required by PubObjectsExportPlugin::getExportDeploymentClassName();
 *  this plugin delivers the uploaded JATS document and PDF galley directly (see
 *  ScopusExportPlugin::depositXML()) rather than through the filter-based XML
 *  export/deployment machinery.
 */

namespace APP\plugins\generic\scopus;

use PKP\context\Context;

class ScopusExportDeployment
{
    public function __construct(public Context $context, public ScopusExportPlugin $plugin)
    {
    }
}
