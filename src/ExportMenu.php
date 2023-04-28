<?php

/**
 * @package   yii2-export
 * @author    Kartik Visweswaran <kartikv2@gmail.com>
 * @copyright Copyright &copy; Kartik Visweswaran, Krajee.com, 2015 - 2021
 * @version   1.4.3
 */

namespace mootensai\export;

use Closure;
use Exception;
use kartik\base\TranslationTrait;
use kartik\dialog\Dialog;
use kartik\dynagrid\Dynagrid;
use kartik\grid\GridView;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\BaseWriter;
use PhpOffice\PhpSpreadsheet\Writer\Csv as WriterCsv;
use Yii;
use yii\base\InvalidConfigException;
use yii\base\Model;
use yii\base\Widget;
use yii\data\ActiveDataProvider;
use yii\data\ArrayDataProvider;
use yii\data\BaseDataProvider;
use yii\db\ActiveQueryInterface;
use yii\db\QueryInterface;
use yii\grid\ActionColumn;
use yii\grid\Column;
use yii\grid\DataColumn;
use yii\grid\SerialColumn;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;
use yii\helpers\Inflector;
use yii\helpers\Json;
use yii\helpers\Url;
use yii\web\JsExpression;
use yii\web\View;

/**
 * Export menu widget. Export tabular data to various formats using the `\PhpOffice\PhpSpreadsheet\Spreadsheet library
 * by reading data from a dataProvider - with configuration very similar to a GridView.
 *
 * @author Kartik Visweswaran <kartikv2@gmail.com>
 * @since  1.0
 */
class ExportMenu extends GridView
{
    use TranslationTrait;

    /**
     * @var string UTF-8 encoding
     */
    const ENCODING_UTF8 = 'utf-8';
    /**
     * @var string HTML (Hyper Text Markup Language) export format
     */
    const FORMAT_HTML = 'Html';
    /**
     * @var string CSV (comma separated values) export format
     */
    const FORMAT_CSV = 'Csv';
    /**
     * @var string Text export format
     */
    const FORMAT_TEXT = 'Txt';
    /**
     * @var string PDF (Portable Document Format) export format
     */
    const FORMAT_PDF = 'Pdf';
    /**
     * @var string Microsoft Excel 95+ export format
     */
    const FORMAT_EXCEL = 'Xls';
    /**
     * @var string Microsoft Excel 2007+ export format
     */
    const FORMAT_EXCEL_X = 'Xlsx';
    /**
     * @var string Set download target for grid export to a popup browser window
     */
    const TARGET_POPUP = '_popup';
    /**
     * @var string Set download target for grid export to a dynamically generated iframe
     */
    const TARGET_IFRAME = '_iframe';
    /**
     * @var string Set download target for grid export to the same open document on the browser
     */
    const TARGET_SELF = '_self';
    /**
     * @var string Set download target for grid export to a new window that auto closes after download
     */
    const TARGET_BLANK = '_blank';

    /**
     * @var string the target for submitting the export form, which will trigger the download of the exported file.
     * Must be one of the `TARGET_` constants. Defaults to [[TARGET_SELF]]. Note if you set [[stream]] to `false`,
     * then this will be always overridden to [[TARGET_SELF]].
     */
    public $target = self::TARGET_SELF;

    /**
     * @var array configuration settings for the Krajee dialog widget that will be used to render alerts and
     * confirmation dialog prompts
     * @see http://demos.krajee.com/dialog
     */
    public $krajeeDialogSettings = [];

    /**
     * @var boolean whether to show a confirmation alert dialog before download. This confirmation dialog will notify
     * user about the type of exported file for download and to disable popup blockers. Defaults to `true`.
     */
    public $showConfirmAlert = true;

    /**
     * @var boolean whether to enable the yii gridview formatter component. Defaults to `true`. If set to `false`, this
     * will render content as `raw` format.
     */
    public $enableFormatter = true;

    /**
     * @var boolean whether to render the export menu as bootstrap button dropdown widget. Defaults to `true`. If set to
     * `false`, this will generate a simple HTML list of links.
     */
    public $asDropdown = true;

    /**
     * @var string the pjax container identifier inside which this menu is being rendered. If set the jQuery export
     * menu plugin will get auto initialized on pjax request completion.
     */
    public $pjaxContainerId;

    /**
     * @var boolean whether to clear all previous / parent buffers. Defaults to `false`.
     */
    public $clearBuffers = false;

    /**
     * @var array the HTML attributes for the export button menu. Applicable only if [[asDropdown]] is set to `true`.
     * The following special options are available:
     * - `label`: _string_, defaults to empty string
     * - `icon`: _string_, defaults to `<i class="glyphicon glyphicon-export"></i>` for BS3 and
     * `<i class="fas fa-external-link-alt"></i>` for BS4
     * - `title`: _string_, defaults to `Export data in selected format`.
     * - `menuOptions`: _array_, the HTML attributes for the dropdown menu.
     * - `itemsBefore`: _array_, any additional items that will be merged/prepended before with the export dropdown list.
     *   This should be similar to the `items` property as supported by [[ButtonDropdown]] widget. Note the page export
     *   items will be automatically generated based on settings in the [[exportConfig]] property.
     * - `itemsAfter`: _array_, any additional items that will be merged/appended after with the export dropdown list. This
     *   should be similar to the `items` property as supported by [[ButtonDropdown]] widget. Note the
     *   page export items will be automatically generated based on settings in the `exportConfig` property.
     */
    public $dropdownOptions = [];

    /**
     * @var boolean whether to initialize data provider and clear models before rendering. Defaults to `false`.
     */
    public $initProvider = false;

    /**
     * @var boolean whether to show a column selector to select columns for export. Defaults to `true`.
     * This is applicable only if [[asDropdown]] is set to `true`. Else this property is ignored.
     */
    public $showColumnSelector = true;

    /**
     * @var boolean enable or disable cell formatting by auto detecting the grid column alignment and format.
     * If set to `false` the format will not be applied but improve performance configured in [[dynagridOptions]].
     */
    public $enableAutoFormat = true;

    /**
     * @var array the configuration of the column names in the column selector. Note: column names will be generated
     * automatically by default. Any setting in this property will override the auto-generated column names. This
     * list should be setup as `$key => $value` where:
     * - `$key`: _integer_, is the zero based index of the column as set in `$columns`.
     * - `$value`: _string_, is the column name/label you wish to display in the column selector.
     */
    public $columnSelector = [];

    /**
     * @var array the HTML attributes for the column selector dropdown button. The following special options are
     * recognized:
     * - `label`: _string_, defaults to empty string.
     * - `icon`: _string_, defaults to `<i class="glyphicon glyphicon-list"></i>` for BS3 and
     * `<i class="fas fa-list"></i>` for BS4
     * - `title`: _string_, defaults to `Select columns for export`.
     */
    public $columnSelectorOptions = [];

    /**
     * @var array the HTML attributes for the column selector menu list.
     */
    public $columnSelectorMenuOptions = [];

    /**
     * @var array the settings for the toggle all checkbox to check / uncheck the columns as a batch. Should be setup as
     * an associative array which can have the following keys:
     * - `show`: _boolean_, whether the batch toggle checkbox is to be shown. Defaults to `true`.
     * - `label`: _string_, the label to be displayed for toggle all. Defaults to `Select Columns`.
     * - `options`: _array_, the HTML attributes for the toggle label text. Defaults to `['class'=>'kv-toggle-all']`
     */
    public $columnBatchToggleSettings = [];

    /**
     * @var array, HTML attributes for the container to wrap the widget. Defaults to:
     * `['class'=>'btn-group', 'role'=>'group']`
     */
    public $container = ['class' => 'btn-group', 'role' => 'group'];

    /**
     * @var string, the template for rendering the content in the container. This will be parsed only if `asDropdown`
     * is `true`. The following tokens will be replaced:
     * - `{columns}`: will be replaced with the column selector dropdown
     * - `{menu}`: will be replaced with export menu dropdown
     */
    public $template = "{columns}\n{menu}";

    /**
     * @var integer timeout for the export function (in seconds), if timeout is < 0, the default PHP timeout will be used.
     */
    public $timeout = -1;

    /**
     * @var array the HTML attributes for the export form.
     */
    public $exportFormOptions = [];

    /**
     * @var array the configuration of additional hidden inputs that will be rendered with the export form. This will be
     * set as an array of hidden input `$name => $setting` pairs where:
     * - `$name`: _string_, is the hidden input name attribute.
     * - `$setting`: _array_, containing the following settings:
     *    - `value`: _string_, the value of the hidden input. Defaults to NULL.
     *    - `options`: _array_, the HTML attributes for the hidden input. Defaults to an empty array `[]`.
     *
     * An example of how you can configure this property is shown below:
     *
     * ```
     * [
     *    'inputName1' => ['value' => 'inputValue1'],
     *    'inputName2' => ['value' => 'inputValue2', 'options' => ['id' => 'inputId2']],
     * ]
     * ```
     */
    public $exportFormHiddenInputs = [];

    /**
     * @var array the selected column indexes for export. If not set this will default to all columns.
     */
    public $selectedColumns;

    /**
     * @var array the column indexes for export that will be disabled for selection in the column selector.
     */
    public $disabledColumns = [];

    /**
     * @var array the column indexes for export that will be hidden for selection in the column selector, but will
     * still be displayed in export output.
     */
    public $hiddenColumns = [];

    /**
     * @var array the column indexes for export that will not be exported at all nor will they be shown in the column
     * selector
     */
    public $noExportColumns = [];

    /**
     * @var string the view file for rendering the export form. DEPRECATED since v1.3.5 (not parsed or used anymore).
     */
    public $exportFormView = '_form';

    /**
     * @var string the view file for rendering the columns selection
     */
    public $exportColumnsView;

    /**
     * @var boolean whether to use font awesome icons for rendering the icons as defined in [[exportConfig]]. If set to
     * `true`, you must load the FontAwesome CSS separately in your application.
     */
    public $fontAwesome = false;

    /**
     * @var boolean whether to strip HTML tags from each of the source column data before rendering the PHP
     * Spreadsheet Cell.
     */
    public $stripHtml = true;

    /**
     * @var array the export configuration. The array keys must be the one of the `format` constants (CSV, HTML, TEXT,
     * EXCEL, PDF) and the array value is a configuration array consisting of these settings:
     * - `label`: _string_, the label for the export format menu item displayed
     * - `icon`: _string_, the glyphicon or font-awesome name suffix to be displayed before the export menu item label. If
     *   set to an empty string_, this will not be displayed.
     * - `iconOptions`: _array_, HTML attributes for export menu icon.
     * - `linkOptions`: _array_, HTML attributes for each export item link.
     * - `filename`: _string_, the base file name for the generated file. Defaults to 'grid-export'. This will be used to generate
     *   a default file name for downloading.
     * - `extension`: _string_, the extension for the file name
     * - `alertMsg`: _string_, the message prompt to show before saving. If this is empty or not set it will not be
     *   displayed.
     * - `mime`: _string_, the mime type (for the file format) to be set before downloading.
     * - `writer`: _string_, the PhpSpreadsheet writer type
     * - `options`: _array_, HTML attributes for the export menu item.
     */
    public $exportConfig = [];

    /**
     * @var string the request parameter ($_GET or $_POST) that will be submitted during export. If not set this will
     *  be auto generated. This should be unique for each export menu widget (for multiple export menu widgets on
     *  same page).
     */
    public $exportRequestParam;

    /**
     * @var string the export type input parameter for export form
     */
    public $exportTypeParam = 'export_type';

    /**
     * @var string the export columns input parameter for export form
     */
    public $exportColsParam = 'export_columns';

    /**
     * @var string the column selector flag parameter for export form
     */
    public $colSelFlagParam = 'column_selector_enabled';

    /**
     * @var array the output style configuration options for each data cell. It must be the style configuration
     * array as required by `\PhpOffice\PhpSpreadsheet\Spreadsheet`.
     */
    public $styleOptions = [];

    /**
     * @var array the output style configuration options for the header row. It must be the style configuration array as
     * required by `\PhpOffice\PhpSpreadsheet\Spreadsheet`.
     */
    public $headerStyleOptions = [];

    /**
     * @var array the output style configuration options for the entire spreadsheet box range. It must be the style
     * configuration array as required by `\PhpOffice\PhpSpreadsheet\Spreadsheet`.
     */
    public $boxStyleOptions = [];

    /**
     * @var array an array of rows to prepend in front of the grid used to create things like a title. Each array
     * should be set with the following settings:
     * - value: string, the value of the merged row
     * - cellFormat: string|null, the explicit cell format to apply (should be one of the
     *   `PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_` constants)
     * - styleOptions: array, array of configuration options to set the style. See $styleOptions on how to configure.
     */
    public $contentBefore = [];

    /**
     * @var array an array of rows to append after the footer row. Each array
     * should be set with the following settings:
     * - value: string, the value of the merged row
     * - cellFormat: string|null, the explicit cell format to apply (should be one of the
     *   `PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_` constants)
     * - styleOptions: array, array of configuration options to set the style. See $styleOptions on how to configure.
     */
    public $contentAfter = [];

    /**
     * @var boolean whether to auto-size the excel output column widths. Defaults to `true`.
     */
    public $autoWidth = true;

    /**
     * @var string encoding for the downloaded file header. Defaults to [[ENCODING_UTF8]].
     */
    public $encoding = self::ENCODING_UTF8;

    /**
     * @var string the exported output file name. Defaults to 'grid-export';
     */
    public $filename;

    /**
     * @var string the folder to save the exported file. Defaults to '@app/runtime/export/'. If the specified folder
     * does not exist, the extension will attempt to create it - else an exception will be thrown.
     */
    public $folder = '@app/runtime/export';

    /**
     * @var string the web accessible path for the saved file location. This property will be parsed only if [[stream]]
     * is false. Note the [[afterSaveView]] property that will render the displayed file link.
     */
    public $linkPath = '/runtime/export';

    /**
     * @var string the name of the file to be appended to [[linkPath]] to generate the complete link. If not set, this
     * will default to the [[filename]].
     */
    public $linkFileName;

    /**
     * @var boolean whether to stream output to the browser.
     */
    public $stream = true;

    /**
     * @var boolean whether to delete file after saving file to [[folder]] and when [[stream]] is `false`. This property
     * will be validated only when [[stream]] is `false`.
     */
    public $deleteAfterSave = false;

    /**
     * @var string|bool the view file to show details of exported file link. This property will be validated only when
     * [[stream]] is `false`. You can set this to `false` to not display any file link details for view. This defaults
     * to the `_view` PHP file in the `views` folder of the extension.
     */
    public $afterSaveView;

    /**
     * @var integer  fetch models from the dataprovider using batches of this size. Set this to `0` (the default) to
     * disable. If `$dataProvider` does not have a pagination object, this parameter is ignored. Setting this
     * property helps reduce memory overflow issues by allowing parsing of models in batches, rather than fetching
     * all models in one go.
     */
    public $batchSize = 0;

    /**
     * @var array, the configuration of various messages that will be displayed at runtime:
     * - allowPopups: string, the message to be shown to disable browser popups for download. Defaults to `Disable any
     *   popup blockers in your browser to ensure proper download.`.
     * - confirmDownload: string, the message to be shown for confirming to proceed with the download. Defaults to `Ok
     *   to proceed?`.
     * - downloadProgress: string, the message to be shown in a popup dialog when download request is executed.
     *   Defaults to `Generating file. Please wait...`.
     * - downloadComplete: string, the message to be shown in a popup dialog when download request is completed.
     *   Defaults to `All done! Click anywhere here to close this window, once you have downloaded the file.`.
     */
    public $messages = [];

    /**
     * @var Closure the callback function on initializing the PhpSpreadsheet library. The anonymous function should have the
     * following signature:
     * ```php
     * function ($spreadsheet, $widget)
     * ```
     * where:
     * - `$spreadsheet`: \PhpOffice\PhpSpreadsheet\Spreadsheet, the Spreadsheet object instance
     * - `$widget`: ExportMenu, the current ExportMenu object instance
     */
    public $onInitExcel = null;

    /**
     * @var Closure the callback function on initializing the writer. The anonymous function should have the following
     * signature:
     * ```php
     * function ($writer, $widget)
     * ```
     * where:
     * - `$writer`: \PhpOffice\PhpSpreadsheet\Writer\BaseWriter, the BaseWriter object instance
     * - `$widget`: ExportMenu, the current ExportMenu object instance
     */
    public $onInitWriter = null;

    /**
     * @var Closure the callback function to be executed on initializing the active sheet. The anonymous function
     * should have the following signature:
     * ```php
     * function ($sheet, $widget)
     * ```
     * where:
     * - `$sheet`: \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet, the Worksheet object instance
     * - `$widget`: ExportMenu, the current ExportMenu object instance
     */
    public $onInitSheet = null;

    /**
     * @var Closure the callback function to be executed on rendering the header cell output content. The anonymous
     * function should have the following signature:
     * ```php
     * function ($cell, $content, $widget)
     * ```
     * where:
     * - `$cell`: \PhpOffice\PhpSpreadsheet\Cell\Cell, is the current Spreadsheet cell being rendered
     * - `$content`: string, is the header cell content being rendered
     * - `$widget`: ExportMenu, the current ExportMenu object instance
     */
    public $onRenderHeaderCell = null;

    /**
     * @var Closure the callback function to be executed on rendering each body data cell content. The anonymous
     * function should have the following signature:
     * ```php
     * function ($cell, $content, $model, $key, $index, $widget)
     * ```
     * where:
     * - `$cell`: \PhpOffice\PhpSpreadsheet\Cell\Cell, the current Spreadsheet cell being rendered
     * - `$content`: string, the data cell content being rendered
     * - `$model`: Model, the data model to be rendered
     * - `$key`: mixed, the key associated with the data model
     * - `$index`: integer, the zero-based index of the data model among the model array returned by [[dataProvider]].
     * - `$widget`: ExportMenu, the current ExportMenu object instance
     */
    public $onRenderDataCell = null;

    /**
     * @var Closure the callback function to be executed on rendering the footer cell output content. The anonymous
     * function should have the following signature:
     * ```php
     * function ($cell, $content, $widget)
     * ```
     * where:
     * - `$sheet`: \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet, the Worksheet object instance
     * - `$content`: string, the footer cell content being rendered
     * - `$widget`: ExportMenu, the current ExportMenu object instance
     */
    public $onRenderFooterCell = null;

    /**
     * @var Closure the callback function to be executed on rendering the sheet. The anonymous function should have the
     * following signature:
     * ```php
     * function ($sheet, $widget)
     * ```
     * where:
     * - `$sheet`: \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet, the Worksheet object instance
     * - `$widget`: ExportMenu, the current ExportMenu object instance
     */
    public $onRenderSheet = null;

    /**
     * @var Closure the callback function to be executed after the output file is generated. This function must return
     * a boolean status of `true` or `false`. A `false` status will abort the post file generation activities. The
     * anonymous function should have the following signature:
     * ```php
     * function ($fileExt, $widget)
     * ```
     * where:
     * - `$fileExt`: _string_, is the generated file extension.
     * - `$widget`: ExportMenu, the current ExportMenu object instance
     */
    public $onGenerateFile = null;

    /**
     * @var array the \PhpOffice\PhpSpreadsheet\Spreadsheet document properties
     */
    public $docProperties = [];

    /**
     * @var boolean enable dynagrid for column selection. If set to `true` the inbuilt export menu column selector
     * functionality will be disabled and not rendered and column settings for dynagrid will be used as per settings
     * configured in [[dynagridOptions]].
     */
    public $dynagrid = false;

    /**
     * @var array dynagrid widget options. Applicable only if [[dynagrid]] is set to `true`.
     */
    public $dynagridOptions = ['options' => ['id' => 'dynagrid-export-menu']];

    /**
     * @var array the PhpSpreadsheet style configuration for a grouped grid row
     */
    public $groupedRowStyle = [
        'font' => [
            'bold' => false,
            'color' => [
                'argb' => Color::COLOR_DARKBLUE,
            ],
        ],
        'fill' => [
            'type' => Fill::FILL_SOLID,
            'color' => [
                'argb' => Color::COLOR_WHITE,
            ],
        ],
    ];

    /**
     * @var string the sheet name. Defaults to 'Worksheet'.
     */
    public $sheetName = 'Worksheet';

    /**
     * @var array|null new supplement sheets to be created. Required for data validation in excel. An example setting:
     * ```
     * 'supplementSheets' => ['city' => $citiesKeyValueArray, 'state' => $stateKeyValueArray],
     * ```
     * If set to empty or null will be ignored.
     */
    public $supplementSheets = null;

    /**
     * @var array|null data for creating excel data validation. An example setting:
     * ```
     * 'dataValidation' => ['sheetName1' => ['cellPosition' => count($array)], 'sheetName2' => []],
     * ```
     * If set to empty or null will be ignored.
     */
    public $dataValidation = null;

    /**
     * @var string the data output format type. Defaults to `ExportMenu::FORMAT_EXCEL_X`.
     */
    public $exportType = self::FORMAT_EXCEL_X;

    /**
     * @var boolean flag to identify if download is triggered
     */
    public $triggerDownload = false;

    /**
     * @var BaseDataProvider the modified data provider for usage with export.
     */
    protected $_provider;

    /**
     * @var array the default export configuration
     */

    protected $_defaultExportConfig = [];

    /**
     * @var Spreadsheet object instance
     */
    protected $_objSpreadsheet;

    /**
     * @var BaseWriter object instance
     */
    protected $_objWriter;

    /**
     * @var Worksheet object instance
     */
    protected $_objWorksheet;

    /**
     * @var integer the header beginning row
     */
    protected $_headerBeginRow = 1;

    /**
     * @var integer  the table beginning row
     */
    protected $_beginRow = 1;

    /**
     * @var integer  the current table end row
     */
    protected $_endRow = 0;

    /**
     * @var integer  the current table end column
     */
    protected $_endCol = 1;

    /**
     * @var boolean whether the column selector is enabled
     */
    protected $_columnSelectorEnabled;

    /**
     * @var array the visble columns for export
     */
    protected $_visibleColumns;

    /**
     * @var array columns to be grouped
     */
    protected $_groupedColumn = [];

    /**
     *
     * @var array grouped row values
     */
    protected $_groupedRow = null;

    /**
     * @var string the data output format type. Defaults to `ExportMenu::FORMAT_EXCEL_X`.
     */
    protected $_exportType;

    /**
     * @var boolean private flag that will use $_POST [[exportRequestParam]] setting if available or use the
     * [[triggerDownload]] setting
     */
    protected $_triggerDownload;

    /**
     * Appends slash to path if it does not exist
     *
     * @param  string  $path
     * @param  string  $s  the path separator
     *
     * @return string
     */
    public static function slash($path, $s = DIRECTORY_SEPARATOR)
    {
        $path = trim($path);
        if (substr($path, -1) !== $s) {
            $path .= $s;
        }

        return $path;
    }

    /**
     * Returns an excel column name.
     *
     * @param  integer  $index  the column index number
     *
     * @return string
     */
    public static function columnName($index)
    {
        $i = intval($index) - 1;
        if ($i >= 0 && $i < 26) {
            return chr(ord('A') + $i);
        }
        if ($i > 25) {
            return (self::columnName($i / 26)).(self::columnName($i % 26 + 1));
        }

        return 'A';
    }

    /**
     * @inheritdoc
     */
    public function init()
    {
        $this->initSettings();
        parent::init();
    }

    /**
     * @inheritdoc
     */
    public function run()
    {
        $this->initI18N(__DIR__);
        $this->initColumnSelector();
        $this->setVisibleColumns();
        $this->initExport();
        if (!$this->_triggerDownload) {
            $this->registerAssets();
            echo $this->renderExportMenu();

            return;
        }

        Yii::$app->getSession()->setFlash('success', 'Report is being generated, please check the <a href="/report/report">Report Page</a> to see progress, download or email.');
        $this->registerAssets();
        echo $this->renderExportMenu();
        return;
    }

    /**
     * Initialize export menu settings
     */
    protected function initSettings()
    {
        $this->showPageSummary = false; // force disable page-summary for `ExportMenu` (issue #162)
        $this->_msgCat = 'kvexport';
        if (empty($this->options['id'])) {
            $this->options['id'] = $this->getId();
        }
        if (empty($this->exportRequestParam)) {
            $this->exportRequestParam = 'exportFull_'.$this->options['id'];
        }
        $path = '@vendor/kartik-v/yii2-export/src/views';
        if (!isset($this->exportColumnsView)) {
            $this->exportColumnsView = "{$path}/_columns";
        }
        if (!isset($this->afterSaveView)) {
            $this->afterSaveView = "{$path}/_view";
        }
        $this->_columnSelectorEnabled = $this->showColumnSelector && $this->asDropdown;
        $request = Yii::$app->request;
        if ($request instanceof \yii\web\Request) {
          $this->_triggerDownload = $request->post($this->exportRequestParam, $this->triggerDownload);
          $this->_exportType = $request->post($this->exportTypeParam, $this->exportType);
        } else {
            $this->_triggerDownload = $this->triggerDownload;
            $this->_exportType = $this->exportType;
        }
        if (!$this->stream) {
            $this->target = self::TARGET_SELF;
        }
        if ($this->_triggerDownload) {
            $this->_columnSelectorEnabled = $request instanceof \yii\web\Request ?
                $request->post($this->colSelFlagParam, $this->_columnSelectorEnabled) :
                $this->_columnSelectorEnabled;
            $this->initSelectedColumns();
        }
        if ($this->dynagrid) {
            $this->_columnSelectorEnabled = false;
            $options = $this->dynagridOptions;
            $options['columns'] = $this->columns;
            if (!isset($options['storage'])) {
                $options['storage'] = DynaGrid::TYPE_DB;
            }
            $options['gridOptions']['dataProvider'] = $this->dataProvider;
            $dynagrid = new DynaGrid($options);
            $this->columns = $dynagrid->getColumns();
        }
    }

    /**
     * Initializes export settings
     */
    public function initExport()
    {
        $this->_provider = clone($this->dataProvider);
        if ($this->batchSize && $this->_provider->pagination) {
            $this->_provider->pagination = clone($this->dataProvider->pagination);
            $this->_provider->pagination->pageSize = $this->batchSize;
            $this->_provider->refresh();
            if (Yii::$app->request->getBodyParam('exportFull_export')) {
                $this->_provider->pagination->page = null;
                Yii::$app->request->setQueryParams([$this->_provider->pagination->pageParam => 1]);
            }
        } else {
            $this->_provider->pagination = false;
        }
        if ($this->initProvider) {
            $this->_provider->prepare(true);
        }
        $this->filterModel = null;
        $this->setDefaultExportConfig();
        $this->exportConfig = ArrayHelper::merge($this->_defaultExportConfig, $this->exportConfig);
        if (!isset($this->filename)) {
            $this->filename = Yii::t('kvexport', 'grid-export');
        }
        Html::addCssClass($this->exportFormOptions, 'kv-export-full-form');
        if (!isset($this->exportFormOptions['id'])) {
            $this->exportFormOptions['id'] = $this->options['id'].'-export-form';
        }
    }

    /**
     * Renders the export menu
     *
     * @return string the export menu markup
     * @throws InvalidConfigException
     * @throws Exception
     */
    public function renderExportMenu()
    {
        $items = $this->asDropdown ? [] : '';
        $notBs3 = !$this->isBs(3);
        Html::addCssClass($this->dropdownOptions, ['btn', $this->getDefaultBtnCss()]);
        foreach ($this->exportConfig as $format => $settings) {
            if (!isset($settings) || $settings === false) {
                continue;
            }
            $label = '';
            if (isset($settings['icon'])) {
                $iconOptions = ArrayHelper::getValue($settings, 'iconOptions', []);
                Html::addCssClass($iconOptions, $settings['icon']);
                $label = Html::tag('i', '', $iconOptions).' ';
            }
            if (isset($settings['label'])) {
                $label .= $settings['label'];
            }
            $fmt = strtolower($format);
            $linkOptions = ArrayHelper::getValue($settings, 'linkOptions', []);
            $linkOptions['id'] = $this->options['id'].'-'.$fmt;
            $linkOptions['data-format'] = $format;
            $options = ArrayHelper::getValue($settings, 'options', []);
            Html::addCssClass($linkOptions, "export-full-{$fmt}");
            if ($this->asDropdown) {
                $items[] = [
                    'label' => $label,
                    'url' => '#',
                    'linkOptions' => $linkOptions,
                    'options' => $options,
                ];
            } else {
                $tag = ArrayHelper::remove($options, 'tag', 'li');
                if ($tag !== false) {
                    $items .= Html::tag($tag, Html::a($label, '#', $linkOptions), $options);
                } else {
                    $items .= Html::a($label, '#', $linkOptions);
                }
            }
        }
        $iconCss = $notBs3 ? 'fas fa-external-link-alt' : 'glyphicon glyphicon-export';
        if ($this->asDropdown) {
            $icon = ArrayHelper::remove($this->dropdownOptions, 'icon', '<i class="'.$iconCss.'"></i>');
            $label = ArrayHelper::remove($this->dropdownOptions, 'label');
            $label = $label === null ? $icon : $icon.' '.$label;
            if (!isset($this->dropdownOptions['title'])) {
                $this->dropdownOptions['title'] = Yii::t('kvexport', 'Export data in selected format');
            }
            $menuOptions = ArrayHelper::remove($this->dropdownOptions, 'menuOptions', []);
            $itemsBefore = ArrayHelper::remove($this->dropdownOptions, 'itemsBefore', []);
            $itemsAfter = ArrayHelper::remove($this->dropdownOptions, 'itemsAfter', []);
            $items = ArrayHelper::merge($itemsBefore, $items, $itemsAfter);
            $opts = [
                'label' => $label,
                'dropdown' => ['items' => $items, 'encodeLabels' => false, 'options' => $menuOptions,],
                'encodeLabel' => false,
            ];

            if (!isset($this->exportContainer['class'])) {
                $this->exportContainer['class'] = 'btn-group';
            }
            /**
             * @var Widget $class
             */
            $class = $this->getDropdownClass(true);
            if ($notBs3) {
                $opts['buttonOptions'] = $this->dropdownOptions;
                $opts['renderContainer'] = false;
                $out = Html::tag('div', $class::widget($opts), $this->exportContainer);
            } else {
                $opts['options'] = $this->dropdownOptions;
                $opts['containerOptions'] = $this->exportContainer;
                $out = $class::widget($opts);
            }
            $replacePairs = ['{menu}' => $out, '{columns}' => $this->renderColumnSelector()];
            $content = strtr($this->template, $replacePairs);

            return Html::tag('div', $content, $this->container);
        } else {
            return $items;
        }
    }

    /**
     * Renders the columns selector
     *
     * @return string the column selector markup
     * @throws Exception
     */
    public function renderColumnSelector()
    {
        if (!$this->_columnSelectorEnabled) {
            return '';
        }

        return $this->render(
            $this->exportColumnsView,
            [
                'id' => $this->options['id'],
                'notBs3' => !$this->isBs(3),
                'isBs4' => $this->isBs(4),
                'options' => $this->columnSelectorOptions,
                'menuOptions' => $this->columnSelectorMenuOptions,
                'columnSelector' => $this->columnSelector,
                'batchToggle' => $this->columnBatchToggleSettings,
                'selectedColumns' => $this->selectedColumns,
                'disabledColumns' => $this->disabledColumns,
                'hiddenColumns' => $this->hiddenColumns,
                'noExportColumns' => $this->noExportColumns,
            ]
        );
    }
    /**
     * Gets the visible columns for export
     *
     * @return array the columns configuration
     */
    public function getVisibleColumns()
    {
        if (!isset($this->_visibleColumns)) {
            $this->setVisibleColumns();
        }

        return $this->_visibleColumns;
    }

    /**
     * Sets visible columns for export
     */
    public function setVisibleColumns()
    {
        $columns = [];
        foreach ($this->columns as $key => $column) {
            $isActionColumn = $column instanceof ActionColumn;
            $isNoExport = in_array($key, $this->noExportColumns) ||
                ($this->showColumnSelector && is_array($this->selectedColumns) && !in_array($key,
                        $this->selectedColumns));
            if ($isActionColumn && !$isNoExport) {
                $this->noExportColumns[] = $key;
            }
            if (!empty($column->hiddenFromExport) || $isActionColumn || $isNoExport) {
                continue;
            }
            $columns[] = $column;
        }
        $this->_visibleColumns = $columns;
    }

    /**
     * Gets the currently selected export type
     *
     * @return string
     */
    public function getExportType()
    {
        return $this->_exportType;
    }

    /**
     * Gets the setting property value for the current export format
     *
     * @param  string  $key  the setting property key for the current export format
     * @param  string  $default  the default value for the property
     *
     * @return mixed
     * @throws Exception
     */
    protected function getSetting($key, $default = null)
    {
        $settings = ArrayHelper::getValue($this->exportConfig, $this->_exportType, []);

        return ArrayHelper::getValue($settings, $key, $default);
    }

    /**
     * Initialize columns selected for export
     */
    protected function initSelectedColumns()
    {
        if (!$this->_columnSelectorEnabled) {
            return;
        }
        $expCols = Yii::$app->request->post($this->exportColsParam, '');
        $this->selectedColumns = empty($expCols) ? array_keys($this->columnSelector) : Json::decode($expCols);
    }

    /**
     * Initialize column selector list
     */
    protected function initColumnSelector()
    {
        if (!$this->_columnSelectorEnabled) {
            return;
        }
        $selector = [];
        Html::addCssClass($this->columnSelectorOptions, ['btn', $this->getDefaultBtnCss(), 'dropdown-toggle']);
        $header = ArrayHelper::getValue($this->columnSelectorOptions, 'header', Yii::t('kvexport', 'Select Columns'));
        $this->columnSelectorOptions['header'] = (!isset($header) || $header === false) ? '' :
            '<li class="dropdown-header">'.$header.'</li><li class="kv-divider"></li>';
        $id = $this->options['id'].'-cols';
        Html::addCssClass($this->columnSelectorMenuOptions, 'dropdown-menu kv-checkbox-list');
        $this->columnSelectorMenuOptions = array_replace_recursive(
            [
                'id' => $id.'-list',
                'role' => 'menu',
                'aria-labelledby' => $id,
            ],
            $this->columnSelectorMenuOptions
        );
        $dataToggle = 'data-'.($this->isBs(5) ? 'bs-' : '').'toggle';
        $this->columnSelectorOptions = array_replace_recursive(
            [
                'id' => $id,
                'icon' => !$this->isBs(3) ? '<i class="fas fa-list"></i>' : '<i class="glyphicon glyphicon-list"></i>',
                'title' => Yii::t('kvexport', 'Select columns to export'),
                'type' => 'button',
                $dataToggle => 'dropdown',
                'aria-haspopup' => 'true',
                'aria-expanded' => 'false',
            ],
            $this->columnSelectorOptions
        );
        foreach ($this->columns as $key => $column) {
            $selector[$key] = $this->getColumnLabel($key, $column);
        }
        $this->columnSelector = array_replace($selector, $this->columnSelector);
        if (!isset($this->selectedColumns)) {
            $keys = array_keys($this->columnSelector);
            $this->selectedColumns = array_combine($keys, $keys);
        }
    }

    /**
     * Fetches the column label
     *
     * @param  integer  $key
     * @param  Column  $column
     *
     * @return string
     */
    protected function getColumnLabel($key, $column)
    {
        $key++;
        $label = Yii::t('kvexport', 'Column').' '.$key;
        if (isset($column->label)) {
            $label = $column->label;
        } elseif (isset($column->header)) {
            $label = $column->header;
        } elseif (isset($column->attribute)) {
            $label = $this->getAttributeLabel($column->attribute);
        } elseif (!$column instanceof DataColumn) {
            $class = explode('\\', get_class($column));
            $label = Inflector::camel2words(end($class));
        }

        return trim(strip_tags(str_replace(['<br>', '<br/>'], ' ', $label)));
    }

    /**
     * Generates the attribute label
     *
     * @param  string  $attribute
     *
     * @return string
     */
    protected function getAttributeLabel($attribute)
    {
        /**
         * @var Model $model
         */
        $provider = $this->dataProvider;
        if ($provider instanceof ActiveDataProvider && $provider->query instanceof ActiveQueryInterface) {
            /** @noinspection PhpPossiblePolymorphicInvocationInspection */
            $modelClass = $provider->query->modelClass;
            $model = $modelClass::instance();

            return $model->getAttributeLabel($attribute);
        } elseif ($provider instanceof ActiveDataProvider && $provider->query instanceof QueryInterface) {
            return Inflector::camel2words($attribute);
        } else {
            $models = $provider->getModels();
            if (($model = reset($models)) instanceof Model) {
                return $model->getAttributeLabel($attribute);
            } else {
                return Inflector::camel2words($attribute);
            }
        }
    }

    /**
     * Sets the default export configuration
     * @throws InvalidConfigException|Exception
     */
    protected function setDefaultExportConfig()
    {
        $isFa = $this->fontAwesome;
        $notBs3 = !$this->isBs(3);
        $this->_defaultExportConfig = [
            self::FORMAT_HTML => [
                'label' => Yii::t('kvexport', 'HTML'),
                'icon' => $notBs3 ? 'fas fa-file-alt' : ($isFa ? 'fa fa-file-text' : 'glyphicon glyphicon-save'),
                'iconOptions' => ['class' => 'text-info'],
                'linkOptions' => [],
                'options' => ['title' => Yii::t('kvexport', 'Hyper Text Markup Language')],
                'alertMsg' => Yii::t('kvexport', 'The HTML export file will be generated for download.'),
                'mime' => 'text/html',
                'extension' => 'html',
                'writer' => self::FORMAT_HTML,
            ],
            self::FORMAT_CSV => [
                'label' => Yii::t('kvexport', 'CSV'),
                'icon' => $notBs3 ? 'fas fa-file-code' : ($isFa ? 'fa fa-file-code-o' : 'glyphicon glyphicon-floppy-open'),
                'iconOptions' => ['class' => 'text-primary'],
                'linkOptions' => [],
                'options' => ['title' => Yii::t('kvexport', 'Comma Separated Values')],
                'alertMsg' => Yii::t('kvexport', 'The CSV export file will be generated for download.'),
                'mime' => 'application/csv',
                'extension' => 'csv',
                'writer' => self::FORMAT_CSV,
            ],
            self::FORMAT_TEXT => [
                'label' => Yii::t('kvexport', 'Text'),
                'icon' => $notBs3 ? 'far fa-file-alt' : ($isFa ? 'fa fa-file-text-o' : 'glyphicon glyphicon-floppy-save'),
                'iconOptions' => ['class' => 'text-muted'],
                'linkOptions' => [],
                'options' => ['title' => Yii::t('kvexport', 'Tab Delimited Text')],
                'alertMsg' => Yii::t('kvexport', 'The TEXT export file will be generated for download.'),
                'mime' => 'text/plain',
                'extension' => 'txt',
                'writer' => self::FORMAT_CSV,
                'delimiter' => "\t",
            ],
            self::FORMAT_PDF => [
                'label' => Yii::t('kvexport', 'PDF'),
                'icon' => $notBs3 ? 'far fa-file-pdf' : ($isFa ? 'fa fa-file-pdf-o' : 'glyphicon glyphicon-floppy-disk'),
                'iconOptions' => ['class' => 'text-danger'],
                'linkOptions' => [],
                'options' => ['title' => Yii::t('kvexport', 'Portable Document Format')],
                'alertMsg' => Yii::t('kvexport', 'The PDF export file will be generated for download.'),
                'mime' => 'application/pdf',
                'extension' => 'pdf',
                'writer' => 'KrajeePdf', // custom Krajee PDF writer using MPdf library
                'useInlineCss' => true,
                'pdfConfig' => [],
            ],
            self::FORMAT_EXCEL => [
                'label' => Yii::t('kvexport', 'Excel 95 +'),
                'icon' => $notBs3 ? 'far fa-file-excel' : ($isFa ? 'fa fa-file-excel-o' : 'glyphicon glyphicon-floppy-remove'),
                'iconOptions' => ['class' => 'text-success'],
                'linkOptions' => [],
                'options' => ['title' => Yii::t('kvexport', 'Microsoft Excel 95+ (xls)')],
                'alertMsg' => Yii::t('kvexport', 'The EXCEL 95+ (xls) export file will be generated for download.'),
                'mime' => 'application/vnd.ms-excel',
                'extension' => 'xls',
                'writer' => self::FORMAT_EXCEL,
            ],
            self::FORMAT_EXCEL_X => [
                'label' => Yii::t('kvexport', 'Excel 2007+'),
                'icon' => $notBs3 ? 'fas fa-file-excel' : ($isFa ? 'fa fa-file-excel-o' : 'glyphicon glyphicon-floppy-remove'),
                'iconOptions' => ['class' => 'text-success'],
                'linkOptions' => [],
                'options' => ['title' => Yii::t('kvexport', 'Microsoft Excel 2007+ (xlsx)')],
                'alertMsg' => Yii::t('kvexport', 'The EXCEL 2007+ (xlsx) export file will be generated for download.'),
                'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'extension' => 'xlsx',
                'writer' => self::FORMAT_EXCEL_X,
            ],
        ];
    }

    /**
     * Registers client assets needed for Export Menu widget
     * @throws Exception
     */
    protected function registerAssets()
    {
        $view = $this->getView();
        Dialog::widget($this->krajeeDialogSettings);
        ExportMenuAsset::register($view);
        $this->messages += [
            'allowPopups' => Yii::t(
                'kvexport',
                'Disable any popup blockers in your browser to ensure proper download.'
            ),
            'confirmDownload' => Yii::t('kvexport', 'Ok to proceed?'),
            'downloadProgress' => Yii::t('kvexport', 'Generating the export file. Please wait...'),
            'downloadComplete' => Yii::t(
                'kvexport',
                'Request submitted! You may safely close this dialog after saving your downloaded file.'
            ),
        ];
        $options = [
            'target' => $this->target,
            'formOptions' => $this->exportFormOptions,
            'messages' => $this->messages,
            'exportType' => $this->_exportType,
            'colSelFlagParam' => $this->colSelFlagParam,
            'colSelEnabled' => $this->_columnSelectorEnabled ? 1 : 0,
            'exportRequestParam' => $this->exportRequestParam,
            'exportTypeParam' => $this->exportTypeParam,
            'exportColsParam' => $this->exportColsParam,
            'exportFormHiddenInputs' => $this->exportFormHiddenInputs,
            'showConfirmAlert' => $this->showConfirmAlert,
            'dialogLib' => ArrayHelper::getValue($this->krajeeDialogSettings, 'libName', 'krajeeDialog'),
        ];
        if ($this->_columnSelectorEnabled) {
            $options['colSelId'] = $this->columnSelectorOptions['id'];
        }
        $options = Json::encode($options);
        $menu = 'kvexpmenu_'.hash('crc32', $options);
        $view->registerJs("var {$menu} = {$options};\n", View::POS_HEAD);
        $script = '';
        foreach ($this->exportConfig as $format => $setting) {
            if (!isset($setting) || $setting === false) {
                continue;
            }
            $id = $this->options['id'].'-'.strtolower($format);
            $options = Json::encode([
                'settings' => new JsExpression($menu),
                'alertMsg' => $setting['alertMsg'],
            ]);
            $script .= "jQuery('#{$id}').exportdata({$options});\n";
        }
        if ($this->_columnSelectorEnabled) {
            $id = $this->columnSelectorMenuOptions['id'];
            ExportColumnAsset::register($view);
            $script .= "jQuery('#{$id}').exportcolumns({});\n";
        }
        if (!empty($script) && isset($this->pjaxContainerId)) {
            $script .= "jQuery('#{$this->pjaxContainerId}').on('pjax:complete', function() {
                {$script}
            });\n";
        }
        $view->registerJs($script);
    }

    /**
     * Raises a callable event
     *
     * @param  string  $event  the event name
     * @param  array  $params  the parameters to the callable function
     *
     * @return mixed
     */
    protected function raiseEvent($event, $params)
    {
        if (isset($this->$event) && is_callable($this->$event)) {
            return call_user_func_array($this->$event, $params);
        }

        return true;
    }

}
