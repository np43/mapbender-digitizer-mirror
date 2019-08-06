<?php

namespace Mapbender\DigitizerBundle\Element;

use Doctrine\DBAL\Connection;
use Mapbender\DataSourceBundle\Component\DataStore;
use Mapbender\DataSourceBundle\Component\DataStoreService;
use Mapbender\DataSourceBundle\Component\FeatureType;
use Mapbender\DataSourceBundle\Element\BaseElement;
use Mapbender\DataSourceBundle\Entity\Feature;
use Mapbender\DigitizerBundle\Component\Uploader;
use Mapbender\DigitizerBundle\Entity\Condition;
use RuntimeException;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;


/**
 * Digitizer Mapbender3 element
 */
class Digitizer extends BaseElement
{
    /** @var string Digitizer element title */
    protected static $title = 'Digitizer';

    /** @var string Digitizer element description */
    protected static $description = 'Georeferencing and Digitizing';

    /** @var int Default maximal search results number */
    protected $maxResults = 2500;

    protected $styleManager;

    /**
     * @inheritdoc
     */
    public function getAssets()
    {

        $mainFiles = array('mapbender.element.digitizer', 'toolset', 'schema', 'schemaAll', 'clustering.mixin',
            'menu', 'contextMenu', 'utilities',
            'featureEditDialog', 'controlFactory', 'featureStyleEditor', 'mapbender.layermanager',
            'mapbender.digitizer-translator',
            'queryEngine');


//        $formItemFiles = array("formItem","formItemBreakLine","formItemButton","formItemCheckbox","formItemContainer","formItemDate",
//            "formItemFieldSet","formItemFile","formItemForm","formItemImage","formItemInput","formItemLabel",
//            "formItemResultTable","formItemSelect","formItemTabs","formItemTextArea","formItemText");

        $js = array();

        foreach ($mainFiles as $file) {
            $js[] = "@MapbenderDigitizerBundle/Resources/public/$file.js";
        }

//        foreach($formItemFiles as $file) {
//            $js[] = "@MapbenderDigitizerBundle/Resources/public/formItems/$file.js";
//        }

        $js[] = "@MapbenderDigitizerBundle/Resources/public/vis-ui/vis-ui.js-built.js";

        $js = array_merge($js, array(
            '@MapbenderCoreBundle/Resources/public/mapbender.container.info.js',
            '../../vendor/blueimp/jquery-file-upload/js/jquery.fileupload.js',
            '../../vendor/blueimp/jquery-file-upload/js/jquery.iframe-transport.js',
            '../../vendor/mapbender/ol4-extensions/drawdonut.js',
            '/components/bootstrap-colorpicker/js/bootstrap-colorpicker.min.js',
            '/components/jquery-context-menu/jquery-context-menu-built.js',
            '/components/select2/select2-built.js',
            '/components/select2/dist/js/i18n/de.js',
            '@MapbenderDigitizerBundle/Resources/public/polyfill/setprototype.polyfill.js',
            '@MapbenderDigitizerBundle/Resources/public/polyfill/confirmDialog.js',
            '@MapbenderDigitizerBundle/Resources/public/plugins/printPlugin.js',
            '@MapbenderDigitizerBundle/Resources/public/lib/jsts.min.js',
            '@MapbenderDigitizerBundle/Resources/public/lib/ol-contextmenu.js',

        ));


        return array(
            'js' => $js,
            'css' => array(
                '/components/select2/select2-built.css',
                '/components/bootstrap-colorpicker/css/bootstrap-colorpicker.min.css',
                '@MapbenderDigitizerBundle/Resources/public/sass/element/digitizer.scss',
                '@MapbenderDigitizerBundle/Resources/public/sass/element/modal.scss',
                "@MapbenderDigitizerBundle/Resources/public/vis-ui/vis-ui.js-built.css",
                '@MapbenderDigitizerBundle/Resources/public/lib/ol-contextmenu.css',


            ),
            'trans' => array(
                'MapbenderDigitizerBundle:Element:digitizer.json.twig',
            ),
        );
    }

    /**
     * @inheritdoc
     */
    public static function getDefaultConfiguration()
    {
        return array(
            "target" => null
        );
    }

    /**
     * @return mixed[]
     */
    /**
     * @return mixed[]
     */
    protected function getFeatureTypeDeclarations()
    {
        return $this->container->getParameter('featureTypes');
    }

    /**
     * Prepare form items for each scheme definition
     * Optional: get featureType by name from global context.
     *
     * @inheritdoc
     * @throws RuntimeException
     * @throws InvalidArgumentException
     */
    public function getConfiguration($public = true)
    {
        $configuration = parent::getConfiguration();
        $configuration['debug'] = isset($configuration['debug']) ? $configuration['debug'] : false;
        $configuration['fileUri'] = $this->container->getParameter('mapbender.uploads_dir') . "/" . FeatureType::UPLOAD_DIR_NAME;
        $featureTypes = null;


        if (isset($configuration["schemes"]) && is_array($configuration["schemes"])) {
            foreach ($configuration['schemes'] as $key => &$scheme) {
               $scheme['featureType'] = (is_string($scheme['featureType'])) ? ($this->getFeatureTypeDeclarations())[$scheme['featureType']] : $scheme['featureType'];
                if ($public) {
                   $scheme['featureType'] = $this->cleanFromInternConfiguration($scheme['featureType']);
                }
                if (isset($scheme['formItems'])) {
                    $scheme['formItems'] = $this->prepareItems($scheme['formItems']);
                }
            }
        }
        return $configuration;
    }

    /**
     * Get schema by name
     *
     * @param array $featureType Feature type name
     * @return FeatureType
     * @throws \Symfony\Component\Config\Definition\Exception\Exception
     */
    protected function createFeatureType($featureType)
    {
        if (is_array($featureType)) {
            $featureType = new FeatureType($this->container, $featureType);
        } else {
            throw new Exception('Feature type schema settings not correct', 2);
        }

        return $featureType;
    }


    /**
     * Prepare request feautre data by the form definition
     *
     * @param $feature
     * @param $formItems
     * @return array
     */
    protected function prepareQueriedFeatureData($feature, $formItems)
    {
        foreach ($formItems as $key => $formItem) {
            if (isset($formItem['children'])) {
                $feature = array_merge($feature, $this->prepareQueriedFeatureData($feature, $formItem['children']));
            } elseif (isset($formItem['type']) && isset($formItem['name'])) {
                switch ($formItem['type']) {
                    case 'select':
                        if (isset($formItem['multiple'])) {
                            $separator = isset($formItem['separator']) ? $formItem['separator'] : ',';
                            if (is_array($feature["properties"][$formItem['name']])) {
                                $feature["properties"][$formItem['name']] = implode($separator, $feature["properties"][$formItem['name']]);
                            }
                        }
                        break;
                }
            }
        }
        return $feature;
    }



    /**
     * Eval code string
     *
     * Example:
     *  self::evalString('Hello, $name', array('name' => 'John'))
     *  returns 'Hello, John'
     *
     * @param string $code Code string.
     * @param array $args Variables this should be able by evaluating.
     * @return string Returns evaluated result.
     * @throws \Exception
     */
    protected static function evalString($code, $args)
    {
        foreach ($args as $key => &$value) {
            ${$key} = &$value;
        }

        $_return = null;
        if (eval("\$_return = \"" . str_replace('"', '\"', $code) . "\";") === false && ($errorDetails = error_get_last())) {
            $lastError = end($errorDetails);
            throw new \Exception($lastError["message"], $lastError["type"]);
        }
        return $_return;
    }

    /**
     * Get form item by name
     *
     * @param $items
     * @param $name
     * @return array
     */
    public function getFormItemByName($items, $name)
    {
        foreach ($items as $item) {
            if (isset($item['name']) && $item['name'] == $name) {
                return $item;
            }
            if (isset($item['children']) && is_array($item['children'])) {
                return $this->getFormItemByName($item['children'], $name);
            }
        }
    }

    /**
     * Search form fields AJAX API
     *
     * @param $request
     * @return array
     * @throws \Exception
     */
    public function selectFormAction($request)
    {
        /** @var Connection $connection */
        $itemDefinition = $request["item"];
        $schemaName = $request["schema"];
        $formData = $request["form"];
        $params = $request["params"];
        $config = $this->getConfiguration();
        $schemaConfig = $config['schemes'][$schemaName];
        $searchConfig = $schemaConfig["search"];
        $searchForm = $searchConfig["form"];
        $item = $this->getFormItemByName($searchForm, $itemDefinition["name"]);
        $query = isset($params["term"]) ? $params["term"] : '';
        $ajaxSettings = $item['ajax'];
        $connection = $this->container->get("doctrine.dbal." . $ajaxSettings['connection'] . "_connection");
        $formData = array_merge($formData, array($item["name"] => $query));
        $sql = self::evalString($ajaxSettings["sql"], $formData);
        $rows = $connection->fetchAll($sql);
        $results = array();

        foreach ($rows as $row) {
            $results[] = array(
                'id' => current($row),
                'text' => end($row),
            );
        }

        return array('results' => $results);
    }

    /**
     * calculate 'where'
     *
     * @param FeatureType $featureType
     * @param array $search
     * @param array $conditions
     * @return string
     * @throws \Exception
     */

    private function restrictSelectAction($featureType,$search,$conditions) {
        $connection = $featureType->getConnection();
        $vars = self::escapeValues($search, $connection);

        $whereConditions = array();
        foreach ($conditions as $condition) {
            $condition = new Condition($condition);
            if ($condition->isSql()) {
                $whereConditions[] = $condition->getOperator();
                $whereConditions[] = '(' . static::evalString($condition->getCode(), $vars) . ')';
            }

            if ($condition->isSqlArray()) {
                $subConditions = array();
                $arrayVars = $vars[$condition->getKey()];

                if (!is_array($arrayVars)) {
                    continue;
                }

                foreach ($arrayVars as $value) {
                    $subConditions[] = '(' .
                        static::evalString(
                            $condition->getCode(),
                            array_merge($vars, array('value' => $value)))
                        . ')';
                }
                $whereConditions[] = 'AND';
                $whereConditions[] = '(' . implode(' ' . $condition->getOperator() . ' ', $subConditions) . ')';
            }
        }

        // Remove first operator
        array_splice($whereConditions, 0, 1);

        return implode(' ', $whereConditions);
    }

    /**
     * Select/search features and return feature collection
     *
     * @param array $request
     * @return array Feature collection
     * @throws ServiceNotFoundException
     * @throws ServiceCircularReferenceException
     * @throws \Exception
     */
    public function selectAction($request)
    {
        $schemaName = $request["schema"];
        $configuration = $this->getEntity()->getConfiguration();
        $schema = $configuration["schemes"][$schemaName];
        $featureType =  $this->createFeatureType($schema['featureType']);


        if (isset($request["where"])) {
            unset($request["where"]);
        }

        if (isset($request["search"])) {
            $request["where"] = $this->restrictSelectAction($featureType,$request["search"],$schema['search']['conditions']);
        }


        $featureCollection = $featureType->search(
            array_merge(
                array(
                    'returnType' => 'FeatureCollection',
                    'maxResults' => $this->maxResults
                ),
                $request
            )
        );

        return $featureCollection;
    }

    /**
     * Remove feature
     *
     * @param $request
     * @return array
     * @throws \Symfony\Component\Config\Definition\Exception\Exception
     */
    public function deleteAction($request)
    {
        $schemaName = $request["schema"];
        $configuration = $this->getEntity()->getConfiguration();
        $schema = $configuration["schemes"][$schemaName];
        $featureType =  $this->createFeatureType($schema['featureType']);

        if ((isset($schema['allowDelete']) && !$schema['allowDelete']) || (isset($schema["allowEditData"]) && !$schema['allowEditData'])) {
            throw new Exception('It is forbidden to delete objects', 2);
        }

        return array(
            'result' => $featureType->remove($request['feature'])
        );
    }

    /**
     * Save feature by request data
     *
     * @param array $request
     * @return array
     * @throws \Exception
     */
    public function saveAction($request)
    {
        $schemaName = $request["schema"];
        $configuration = $this->getEntity()->getConfiguration();
        $schema = $configuration["schemes"][$schemaName];
        $featureType =  $this->createFeatureType($schema['featureType']);

        $connection = $featureType->getDriver()->getConnection();
        $results = array();

        if (isset($schema["allowEditData"]) && !$schema["allowEditData"]) {
            throw new Exception("It is forbidden to save objects", 2);
        }

        if (isset($schema["allowSave"]) && !$schema["allowSave"]) {
            throw new Exception("It is forbidden to save objects", 2);
        }

        // save once
        if (isset($request['feature'])) {
            $request['features'] = array($request['feature']);
        }


        // save collection
        if (isset($request['features']) && is_array($request['features'])) {
            foreach ($request['features'] as $feature) {
                /**
                 * @var $feature Feature
                 */
                $featureData = $this->prepareQueriedFeatureData($feature, $schema['formItems']);

                foreach ($featureType->getFileInfo() as $fileConfig) {
                    if (!isset($fileConfig['field']) || !isset($featureData["properties"][$fileConfig['field']])) {
                        continue;
                    }
                    $url = $featureType->getFileUrl($fileConfig['field']);
                    $requestUrl = $featureData["properties"][$fileConfig['field']];
                    $newUrl = str_replace($url . "/", "", $requestUrl);
                    $featureData["properties"][$fileConfig['field']] = $newUrl;
                }

                $feature = $featureType->save($featureData);
                $results = array_merge($featureType->search(array(
                    'srid' => $feature->getSrid(),
                    'where' => $connection->quoteIdentifier($featureType->getUniqueId()) . '=' . $connection->quote($feature->getId()))));
            }

        }
        foreach ($results as &$result) {
            $result->setAttributes(array("schemaName" => $schemaName));
        }
        $results = $featureType->toFeatureCollection($results);


        return $results;

    }

    /**
     * Upload file
     *
     * @param $request
     * @return array
     */
    public function uploadFileAction($request)
    {
        $schemaName = $request["schema"];
        $configuration = $this->getEntity()->getConfiguration();
        $schema = $configuration["schemes"][$schemaName];
        $featureType =  $this->createFeatureType($schema['featureType']);

        if (isset($schema['allowEditData']) && !$schema['allowEditData']) {
            throw new Exception("It is forbidden to save objects", 2);
        }

        $fieldName = $request['field'];
        $urlParameters = array('schema' => $schemaName,
            'fid' => $request["fid"],
            'field' => $fieldName);
        $serverUrl = preg_replace('/\\?.+$/', "", $_SERVER["REQUEST_URI"]) . "?" . http_build_query($urlParameters);
        $uploadDir = $featureType->getFilePath($fieldName);
        $uploadUrl = $featureType->getFileUrl($fieldName) . "/";
        $urlParameters['uploadUrl'] = $uploadUrl;
        $uploadHandler = new Uploader(array(
            'upload_dir' => $uploadDir . "/",
            'script_url' => $serverUrl,
            'upload_url' => $uploadUrl,
            'accept_file_types' => '/\.(gif|jpe?g|png|pdf|zip)$/i',
            'print_response' => false,
            'access_control_allow_methods' => array(
                'OPTIONS',
                'HEAD',
                'GET',
                'POST',
                'PUT',
                'PATCH',
                //                        'DELETE'
            ),
        ));

        return array_merge(
            $uploadHandler->get_response(),
            $urlParameters
        );
    }

    /**
     * Clean feature type configuration for public use
     *
     * @param array $featureType
     * @return array
     */
    protected function cleanFromInternConfiguration(array $featureType)
    {
        foreach (array(
                     'filter',
                     'geomField',
                     'connection',
                     'sql',
                     'events'
                 ) as $keyName) {
            unset($featureType[$keyName]);
        }
        return $featureType;
    }


    /**
     * Escape request variables.
     * Deny SQL injections.
     *
     * @param array $vars
     * @param Connection $connection
     * @return array
     */
    protected static function escapeValues($vars, $connection)
    {
        $results = array();
        foreach ($vars as $key => $value) {
            $quotedValue = null;
            if (is_numeric($value)) {
                $quotedValue = intval($value);
            } elseif (is_array($value)) {
                $quotedValue = self::escapeValues($value, $connection);
            } else {
                $quotedValue = $connection->quote($value);
                if ($quotedValue[0] === '\'') {
                    $quotedValue = preg_replace("/^'|'$/", null, $quotedValue);
                }
            }
            $results[$key] = $quotedValue;
        }
        return $results;
    }



}
