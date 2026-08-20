{**
 * templates/settingsForm.tpl
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * Scopus plugin settings.
 *
 *}
<script type="text/javascript">
	$(function() {ldelim}
		// Attach the form handler.
		$('#scopusSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim})
</script>
<div class="legacyDefaults">
	<form class="pkp_form" method="post" id="scopusSettingsForm" action="{url router=PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" plugin="ScopusExportPlugin" category="importexport" verb="save"}">
		{csrf}
		{include file="controllers/notification/inPlaceNotification.tpl" notificationId="scopusSettingsFormNotification"}
		{fbvFormArea id="scopusSettingsFormArea"}
			<p class="pkp_help">
				{translate key="plugins.importexport.scopus.description"}
			</p>
			<br/>
			{fbvFormSection list="true"}
				{fbvElement type="checkbox" id="automaticRegistration" label="plugins.importexport.scopus.settings.form.automaticRegistration.description" checked=$automaticRegistration|compare:true}
			{/fbvFormSection}

			{capture assign="sectionTitle"}{translate key="plugins.importexport.scopus.endpoint"}{/capture}
			{fbvFormSection id="formSection" title=$sectionTitle translate=false class="endpointContainer"}
				{fbvElement type="text" id="host" value=$host label="plugins.importexport.scopus.host" maxlength="120" size=$fbvStyles.size.MEDIUM}
				{fbvElement type="text" id="port" value=$port label="plugins.importexport.scopus.port" maxlength="5" size=$fbvStyles.size.MEDIUM}
				{fbvElement type="text" id="path" value=$path label="plugins.importexport.scopus.path" maxlength="120" size=$fbvStyles.size.MEDIUM}
				{fbvElement type="text" id="username" value=$username label="plugins.importexport.scopus.username" maxlength="120" size=$fbvStyles.size.MEDIUM}
				{fbvElement type="text" password=true id="password" value=$password label="plugins.importexport.scopus.password" maxlength="120" size=$fbvStyles.size.MEDIUM}
			{/fbvFormSection}
		{/fbvFormArea}
		{fbvFormButtons submitText="common.save" hideCancel="true"}
	</form>
</div>
