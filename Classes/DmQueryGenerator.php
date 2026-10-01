<?php

namespace DirectMailTeam\DirectMail;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use Doctrine\DBAL\Exception as DBALException;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\DataHandling\PageDoktypeRegistry;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Messaging\FlashMessageRendererResolver;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Lowlevel\Controller\QuerySearchController;

/**
 * Used to generate queries for selecting users in the database
 *
 * @author		Kasper Skårhøj <kasper@typo3.com>
 * @author		Stanislas Rolland <stanislas.rolland(arobas)fructifor.ca>
 */
class DmQueryGenerator extends QuerySearchController
{
    protected array $allowedTables = ['tt_address', 'fe_users'];

    public function __construct(
        ?IconFactory $iconFactory = null,
        ?UriBuilder $uriBuilder = null,
        ?ModuleTemplateFactory $moduleTemplateFactory = null,
        ?TcaSchemaFactory $tcaSchemaFactory = null,
        ?FlashMessageRendererResolver $flashMessageRendererResolver = null,
        ?PageDoktypeRegistry $pageDoktypeRegistry = null,
        ?ComponentFactory $componentFactory = null
    ) {
        parent::__construct(
            $iconFactory ?? GeneralUtility::makeInstance(IconFactory::class),
            $uriBuilder ?? GeneralUtility::makeInstance(UriBuilder::class),
            $moduleTemplateFactory ?? GeneralUtility::makeInstance(ModuleTemplateFactory::class),
            $tcaSchemaFactory ?? GeneralUtility::makeInstance(TcaSchemaFactory::class),
            $flashMessageRendererResolver ?? GeneralUtility::makeInstance(FlashMessageRendererResolver::class),
            $pageDoktypeRegistry ?? GeneralUtility::makeInstance(PageDoktypeRegistry::class),
            $componentFactory ?? GeneralUtility::makeInstance(ComponentFactory::class)
        );
    }

    public function mkTableSelect(string $name, string $cur): string
    {
        $out = [];
        $out[] = '<select class="form-select t3js-submit-change" name="' . $name . '">';
        $out[] = '<option value=""></option>';
        foreach ($GLOBALS['TCA'] as $tN => $value) {
            //if ($this->getBackendUserAuthentication()->check('tables_select', $tN)) {
            if ($this->getBackendUserAuthentication()->check('tables_select', $tN) && in_array($tN, $this->allowedTables)) {
                $label = $this->getLanguageService()->sL($GLOBALS['TCA'][$tN]['ctrl']['title']);
                if ($this->showFieldAndTableNames) {
                    $label .= ' [' . $tN . ']';
                }
                $out[] = '<option value="' . htmlspecialchars($tN) . '"' . ($tN == $cur ? ' selected' : '') . '>' . htmlspecialchars($label) . '</option>';
            }
        }
        $out[] = '</select>';
        return implode(LF, $out);
    }

    /**
     * Query marker
     *
     * @param array $allowedTables
     *
     * @return array
     */
    public function queryMakerDM(ServerRequestInterface $request, array $allowedTables = []): array
    {
        if (count($allowedTables)) {
            $this->allowedTables = $allowedTables;
        }

        $output = '';
        $selectQueryString = '';
        // Query Maker:
        $this->init('queryConfig', $this->MOD_SETTINGS['queryTable'] ?? '', '', $this->MOD_SETTINGS);
        if ($this->formName) {
            $this->setFormName($this->formName);
        }
        $tmpCode = $this->makeSelectorTable($this->MOD_SETTINGS, $request);
        $output .= '<div id="query"></div><h2>Make query</h2><div>' . $tmpCode . '</div>';
        $mQ = $this->MOD_SETTINGS['search_query_makeQuery'] ?? '';

        // Make form elements:
        if ($this->table && is_array($GLOBALS['TCA'][$this->table])) {
            if ($mQ) {
                // Show query
                $this->enablePrefix = true;
                $queryString = $this->getQuery($this->queryConfig);
                $selectQueryString = $this->getSelectQuery($queryString);
                $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($this->table);

                $isConnectionMysql = strpos($connection->getServerVersion(), 'MySQL') === 0;
                $fullQueryString = '';
                try {
                    $fullQueryString = $selectQueryString;
                    $dataRows = $connection->executeQuery($selectQueryString)->fetchAllAssociative();
                    //$output .= '<h2>SQL query</h2><div><code>' . htmlspecialchars($fullQueryString) . '</code></div>';
                    $cPR = $this->getQueryResultCode($mQ, $dataRows, $this->table, $request);
                    $output .= '<h2>' . ($cPR['header'] ?? '') . '</h2><div>' . ($cPR['content'] ?? '') . '</div>';
                } catch (DBALException $e) {
                    $output .= '<h2>SQL query</h2><div><code>' . htmlspecialchars($fullQueryString) . '</code></div>';
                    $out = '<p><strong>Error: <span class="text-danger">'
                        . htmlspecialchars($e->getMessage())
                        . '</span></strong></p>';
                    $output .= '<h2>SQL error</h2><div>' . $out . '</div>';
                }
            }
        }
        return ['<div class="database-query-builder">' . $output . '</div>', $selectQueryString];
    }

    public function getQueryDM(bool $queryLimitDisabled, ?ServerRequestInterface $request = null): string
    {
        $this->init('queryConfig', $this->MOD_SETTINGS['queryTable'] ?? '', '', $this->MOD_SETTINGS);
        if (!$this->table || !is_array($GLOBALS['TCA'][$this->table] ?? null)
            || !($this->MOD_SETTINGS['search_query_makeQuery'] ?? false)
        ) {
            return '';
        }
        // Prepare the field lists like makeSelectorTable(), but without rendering the form: it creates
        // backend URIs with form tokens, which fail without a backend session (CLI / scheduler)
        $this->setAndCleanUpExternalLists('queryFields', 'uid', 'uid');
        $this->setAndCleanUpExternalLists('queryGroup', '');
        $this->setAndCleanUpExternalLists('queryOrder', '');
        $this->extFieldLists['queryLimit'] = $queryLimitDisabled
            ? ''
            : (string)(($this->MOD_SETTINGS['queryLimit'] ?? '') ?: 100);
        $queryConfig = (string)($this->MOD_SETTINGS['queryConfig'] ?? '');
        $queryConfig = $queryConfig !== '' ? unserialize($queryConfig, ['allowed_classes' => false]) : [];
        $this->queryConfig = $this->cleanUpQueryConfig(is_array($queryConfig) ? $queryConfig : []);
        $this->enablePrefix = true;

        return $this->getSelectQuery($this->getQuery($this->queryConfig));
    }

    /**
     * Sets the query settings (queryTable, queryConfig, queryLimit, ...), the generator is not called
     * as module controller, so menuConfig() never fills them from the request
     */
    public function setModSettings(array $modSettings): void
    {
        $this->MOD_SETTINGS = $modSettings;
    }

    public function setFormName(string $formName): void
    {
        $this->formName = trim($formName);
    }
}
