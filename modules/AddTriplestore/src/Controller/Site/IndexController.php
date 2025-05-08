<?php

namespace AddTriplestore\Controller\Site;

require 'vendor/autoload.php';

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Laminas\Http\Client;
use Laminas\Log\Logger;
use Laminas\Log\Writer\Stream;
use EasyRdf\Graph;
use Laminas\Form\FormInterface;
use Laminas\Router\RouteStackInterface;

class IndexController extends AbstractActionController
{
    private $graphdbEndpoint = "http://localhost:7200/repositories/arch-project-shacl/rdf-graphs/service";
    private $graphdbQueryEndpoint = "http://localhost:7200/repositories/arch-project-shacl";
    private $baseDataGraphUri = "http://www.arch-project.com/";
    private $router;
    private $httpClient;
    private $excavationIdentifier = "0"; // Default to the "0" graph
        

    public function __construct(RouteStackInterface $router, Client $httpClient)
    {
        $this->router = $router;
        $this->httpClient = $httpClient;
    }

    public function indexAction()
    {
        $site = $this->currentSite();
        return new ViewModel(['site' => $site]);
    }


public function uploadAction()
{
    // Get all POST data
    $postData = $this->params()->fromPost();
    
    // Log the received data for debugging
    error_log('Received form submission: ' . print_r($postData, true), 3, OMEKA_PATH . '/logs/excavation-form-debug.log');
    
    // Determine upload type - excavation or arrowhead
    $uploadType = $this->params()->fromPost('upload_type');
    $itemSetId = $this->params()->fromPost('item_set_id');
    
    // Process the form data based on upload type
    if ($uploadType == 'excavation') {
        // Extract excavation data
        $excavationData = [];
        // Extract context data
        $contextData = $this->processEntitySelection(
            $this->params()->fromPost('existing_context'),
            [
                'id' => $this->params()->fromPost('new_context_id'),
                'description' => $this->params()->fromPost('new_context_description')
            ],
            'Context'
        );
        
        // Extract SVU data
        $svuData = $this->processEntitySelection(
            $this->params()->fromPost('existing_svu'),
            [
                'id' => $this->params()->fromPost('new_svu_id'),
                'description' => $this->params()->fromPost('new_svu_description'),
                'lower_year' => $this->params()->fromPost('new_svu_lower_year'),
                'lower_bc' => $this->params()->fromPost('new_svu_lower_bc') ? true : false,
                'upper_year' => $this->params()->fromPost('new_svu_upper_year'),
                'upper_bc' => $this->params()->fromPost('new_svu_upper_bc') ? true : false
            ],
            'SVU'
        );
        
        // Extract encounter data
        $encounterData = $this->processEntitySelection(
            $this->params()->fromPost('existing_encounter'),
            [
                'date' => $this->params()->fromPost('new_encounter_date'),
                'depth' => $this->params()->fromPost('new_encounter_depth')
            ],
            'EncounterEvent'
        );
        
        // Generate a unique excavation identifier
        $excavationIdentifier = $this->params()->fromPost('new_context_id') ?? 
                                $this->params()->fromPost('new_svu_id') ?? 
                                'EXC-' . uniqid();
        
        // Convert form data to TTL
        $ttlData = $this->prepareTtlFromExcavationData(
            $excavationIdentifier, 
            $excavationData, 
            $contextData, 
            $svuData, 
            $encounterData
        );
        
        // Create an item set and upload TTL to triplestore
        try {
            // Create item set for the excavation
            $itemSetData = [
                'dcterms:title' => [
                    [
                        'type' => 'literal',
                        '@value' => "Excavation $excavationIdentifier"
                    ]
                ],
                'dcterms:description' => [
                    [
                        'type' => 'literal',
                        '@value' => "Item set for excavation with identifier $excavationIdentifier"
                    ]
                ],
                'o:is_public' => true
            ];
            
            // Create the item set
            $itemSetResponse = $this->api()->create('item_sets', $itemSetData);
            $itemSetId = $itemSetResponse->getContent()->id();
            
            // Store the mapping
            $this->storeMappingBetweenItemSetAndExcavation($itemSetId, $excavationIdentifier);
            
            // Upload TTL to the triplestore
            $uploadResult = $this->uploadTtlData($ttlData, $itemSetId);
            
            // Redirect to the excavation form with success message
            return $this->redirect()->toUrl($this->url()->fromRoute('site/collecting', [
                'site-slug' => $this->currentSite()->slug(),
                'form-id' => 3, // Excavation form ID
                'action' => 'uploadExcavationForm'
            ], [
                'query' => [
                    'result' => $uploadResult,
                    'item_set_id' => $itemSetId
                ]
            ]));
            
        } catch (\Exception $e) {
            // Log the error
            error_log('Failed to create item set: ' . $e->getMessage(), 3, OMEKA_PATH . '/logs/excavation-submission.log');
            
            // Redirect with error message
            return $this->redirect()->toUrl($this->url()->fromRoute('site/collecting', [
                'site-slug' => $this->currentSite()->slug(),
                'form-id' => 3, // Excavation form ID
                'action' => 'uploadExcavationForm'
            ], [
                'query' => [
                    'result' => 'Error: ' . $e->getMessage()
                ]
            ]));
        }
    } else if ($uploadType == 'arrowhead') {
        // Process arrowhead submission
        // ...similar to excavation processing
        // Return after processing
    }
    
    // Default response if no specific upload type was recognized
    return $this->redirect()->toUrl($this->url()->fromRoute('site', ['site-slug' => $this->currentSite()->slug()]));
}


    private function getCollectingForm(): FormInterface
    {
        try {
            $collectingFormRepresentation = $this->getCollectingFormRepresentation(1); // Adjust form ID as needed
            $collectingForm = $collectingFormRepresentation->getForm();
            $this->modifyCollectingFormAction($collectingForm); // Ensure correct form action
            return $collectingForm;
        } catch (\Exception $e) {
            // Log the error
            error_log('Error getting Collecting form: ' . $e->getMessage());
            // Return a simple form or null to avoid crashing the page
            return new \Laminas\Form\Form('error-form'); // Or return null;
        }
    }

    private function getCollectingFormRepresentation(int $formId)
    {
        return $this->getServiceLocator()->get('Omeka\ApiManager')
            ->read('collecting_forms', $formId)
            ->getContent();
    }

    private function modifyCollectingFormAction(FormInterface $collectingForm): void
    {
        $uploadUrl = $this->router->assemble(
            ['site-slug' => $this->currentSite()->slug()],
            ['name' => 'site/add-triplestore/upload', 'only_uri' => true]
        );
        $collectingForm->setAttribute('action', $uploadUrl);
    }

    private function processFileUpload($request, ?string $uploadType, ?int $itemSetId): string
    {
    $file = $request->getFiles()->file;
    $fileExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $fileType = $file['type'];

    if (strtolower($fileExtension) === 'ttl' && $fileType !== 'application/x-turtle') {
        $fileType = 'application/x-turtle';
    }

    if (!in_array($fileType, ['application/x-turtle', 'application/xml', 'text/xml'])) {
        return 'Invalid file type. Please upload a valid .ttl or .xml file.';
    }

    try {
        if ($fileType === 'application/xml' || $fileType === 'text/xml') {
            $rdfXmlData = $this->xmlParser($file);
            if (!is_string($rdfXmlData) || strpos($rdfXmlData, 'Failed') === false) {
                $ttlData = $this->xmlTtlConverter($rdfXmlData);
            } else {
                throw new \Exception('Failed to process XML file: ' . $rdfXmlData);
            }
        } else {
            $ttlData = file_get_contents($file['tmp_name']);
        }

        $this->validateUploadType($ttlData, $uploadType);
        return $this->uploadTtlData($ttlData, $itemSetId); // Pass itemSetId

    } catch (\Exception $e) {
        return 'Error processing file: ' . $e->getMessage();
    }
}



private function processFormSubmission($request, ?string $uploadType, ?int $itemSetId): string
{
    $formData = $request->getQuery('form_data'); // Get form data from query
    $formData = is_array($formData) ? $formData : []; // Ensure it's an array
    error_log('Collecting Form Data (GET): ' . print_r($formData, true), 3, OMEKA_PATH . '/logs/form-data.log');

    try {
        $ttlData = $this->transformCollectingFormDataToTTL($formData, $uploadType);
        if ($ttlData) {
            return $this->uploadTtlData($ttlData, $itemSetId); // Pass itemSetId
        } else {
            return 'Error: Could not transform form data to TTL.';
        }
    } catch (\Exception $e) {
        return 'Error processing form data: ' . $e->getMessage();
    }
}


private function transformCollectingFormDataToTTL(array $formData, ?string $uploadType): ?string
{
    $ttl = '';
    $baseUri = $this->baseDataGraphUri . $this->excavationIdentifier;

    if ($uploadType === 'arrowhead') {
        $ttl .= "@prefix ah: <http://www.purl.com/ah/ms/ahMS#> .\n";
        $ttl .= "@prefix dcterms: <http://purl.org/dc/terms/> .\n";

        //  *** ADAPT THIS SECTION TO YOUR ARROWHEAD FORM  ***
        //  Use error_log(print_r($formData, true), 3, OMEKA_PATH . '/logs/form-data.log');
        //  to inspect the $formData and adjust the field names accordingly
        if (isset($formData['prompt_1'])) { // Example: 'prompt_1' is a field name
            $ttl .= "    ah:shape \"{$formData['prompt_1']}\" ;\n";
        }
        if (isset($formData['prompt_2'])) {
            $ttl .= "    dcterms:identifier \"{$formData['prompt_2']}\" ;\n";
        }
        //  ...  Map other fields ...

        $ttl .= "    .\n";

    } elseif ($uploadType === 'excavation') {
        $ttl .= "@prefix excav: <https://purl.org/ah/ms/excavationMS#> .\n";
        $ttl .= "@prefix dcterms: <http://purl.org/dc/terms/> .\n";
        $ttl .= "@prefix crmarchaeo: <http://www.cidoc-crm.org/extensions/crmarchaeo/> .\n";

        //  *** ADAPT THIS SECTION TO YOUR EXCAVATION FORM  ***
        //  Use error_log(print_r($formData, true), 3, OMEKA_PATH . '/logs/form-data.log');
        //  to inspect the $formData and adjust the field names accordingly
        if (isset($formData['prompt_3'])) {
            $ttl .= "    dcterms:title \"{$formData['prompt_3']}\" ;\n";
        }
        if (isset($formData['prompt_4'])) {
            $ttl .= "    dcterms:description \"{$formData['prompt_4']}\" ;\n";
        }
        //  ...  Map other fields ...

        $ttl .= "    .\n";
    }

    return !empty(trim($ttl)) ? $ttl : null;
}


private function uploadTtlData(string $ttlData, ?int $itemSetId = null): string {
    // Check if this is excavation data
    $isExcavation = false;
    $excavationIdentifier = "0"; // Default to "0" graph
    
    try {
        $this->validateUploadType($ttlData, 'excavation');
        $isExcavation = true;
        // Extract excavation identifier for graph organization
        $extractedId = $this->extractExcavationIdentifier($ttlData);
        if ($extractedId) {
            $excavationIdentifier = $extractedId;
        }
        error_log('Extracted excavation identifier: ' . $excavationIdentifier, 3, OMEKA_PATH . '/logs/excavation-debug-final.log');
    } catch (\Exception $e) {
        // If validation fails, it means the data is not excavation data
        error_log('Validation for excavation failed: ' . $e->getMessage(), 3, OMEKA_PATH . '/logs/excavation-debug.log');
        
        // Check if this item belongs to an excavation item set
        if ($itemSetId) {
            $excavationId = $this->getExcavationIdentifierFromItemSet($itemSetId);
            if ($excavationId) {
                $excavationIdentifier = $excavationId;
                error_log('Using excavation ID from item set: ' . $excavationIdentifier, 3, OMEKA_PATH . '/logs/excavation-debug.log');
            }
        }
    }

    error_log('is excavation: ' . ($isExcavation ? 'true' : 'false'), 3, OMEKA_PATH . '/logs/excavation-debug.log');

    // If it's excavation data and no itemSetId is provided, create an item set
    if ($isExcavation && !$itemSetId) {
        error_log('Attempting to extract excavation identifier', 3, OMEKA_PATH . '/logs/excavation-debug.log');
        
        if ($excavationIdentifier) {
            try {
                // Create a new item set directly using the API manager
                $response = $this->api()->create('item_sets', [
                    'dcterms:title' => [
                        [
                            'type' => 'literal',
                            'property_id' => 1,
                            '@value' => "Excavation $excavationIdentifier"
                        ]
                    ],
                    'dcterms:description' => [
                        [
                            'type' => 'literal',
                            'property_id' => 4,
                            '@value' => "Item set for excavation $excavationIdentifier containing all related findings"
                        ]
                    ],
                    'o:is_public' => true
                ]);
                
                // If successful, get the new item set ID
                if ($response) {
                    $newItemSet = $response->getContent();
                    $itemSetId = $newItemSet->id();
                    error_log('Successfully created item set with ID: ' . $itemSetId, 3, OMEKA_PATH . '/logs/excavation-debug.log');
                    
                    // Store the excavation identifier in a site setting or other persistent storage
                    $this->storeMappingBetweenItemSetAndExcavation($itemSetId, $excavationIdentifier);
                } else {
                    error_log('Empty response when creating item set', 3, OMEKA_PATH . '/logs/excavation-debug.log');
                }
            } catch (\Exception $e) {
                error_log('Error creating item set: ' . $e->getMessage(), 3, OMEKA_PATH . '/logs/excavation-debug.log');
            }
        }
    } else if (!$isExcavation && $itemSetId) {
        // This is an arrowhead or other item being added to an existing excavation
        // Retrieve the excavation identifier associated with this item set
        $excavationIdentifier = $this->getExcavationIdentifierFromItemSet($itemSetId);
        error_log('Retrieved excavation identifier for item set ' . $itemSetId . ': ' . $excavationIdentifier, 3, OMEKA_PATH . '/logs/excavation-debug.log');
    }
    
    // Now proceed with the regular upload process
    // First, upload to GraphDB with the excavation identifier if available
    $graphDbResult = $this->sendToGraphDB($ttlData, $excavationIdentifier);
    
    if (strpos($graphDbResult, 'successfully') !== false) {
        // If GraphDB upload is successful, then process in Omeka S
        $omekaResult = $this->transformTtlToOmekaSData($ttlData, $itemSetId);
        $omekaResponse = $this->sendToOmekaS($omekaResult);
        
        if (empty($omekaResponse['errors'])) {
            $createdItems = $omekaResponse['created_items'];
            $updatedCount = 0;
            
            foreach ($createdItems as $item) {
                $itemId = $item['o:id']; // Get the Omeka assigned ID
                
                // Update titles based on content type
                if ($isExcavation) {
                    $title = "Excavation $excavationIdentifier Item $itemId";
                } else {
                    $title = "Arrowhead $itemId" . ($excavationIdentifier ? " (Excavation $excavationIdentifier)" : "");
                }
                
                // Update the title with the Omeka ID
                try {
                    $updateResult = $this->api()->update('items', $itemId, [
                        'dcterms:title' => [
                            [
                                'type' => 'literal',
                                'property_id' => 1,
                                '@value' => $title
                            ]
                        ]
                    ], [], ['isPartial' => true]);
                    
                    if ($updateResult) {
                        $updatedCount++;
                    }
                } catch (\Exception $e) {
                    error_log('Error updating item title: ' . $e->getMessage(), 3, OMEKA_PATH . '/logs/excavation-debug.log');
                }
            }
            
            if ($isExcavation && $itemSetId) {
                return "Data uploaded successfully to both GraphDB and Omeka S. Created Item Set #{$itemSetId} for excavation '{$excavationIdentifier}' and " . 
                      count($createdItems) . " items with updated titles.";
            } else {
                return 'Data uploaded successfully to both GraphDB and Omeka S. Created ' . 
                      count($createdItems) . ' items with updated titles.';
            }
        } else {
            return 'Data uploaded to GraphDB, but Omeka S errors: ' . 
                  implode('; ', $omekaResponse['errors']);
        }
    } else {
        return 'Failed to upload data to GraphDB: ' . $graphDbResult;
    }
}

private function storeMappingBetweenItemSetAndExcavation($itemSetId, $excavationId)
{
    // Get existing mappings from site settings
    $mappings = $this->siteSettings()->get('excavation_itemset_mappings', []);
    
    // Add the new mapping
    $mappings[$itemSetId] = $excavationId;
    
    // Save the updated mappings
    $this->siteSettings()->set('excavation_itemset_mappings', $mappings);
    
    error_log('Stored mapping: Item Set ' . $itemSetId . ' -> Excavation ' . $excavationId, 3, OMEKA_PATH . '/logs/excavation-mappings.log');
}



/**
 * Extract the excavation identifier from TTL data
 * 
 * @param string $ttlData
 * @return string|null
 */
private function extractExcavationIdentifier(string $ttlData): ?string
{
    error_log('Attempting to extract excavation identifier');
    error_log('Full TTL data: ' . $ttlData);

    // First try to find dcterms:identifier
    if (preg_match('/dct:identifier\s+"([^"]+)"\^\^xsd:string\s*;/', $ttlData, $matches)) {
        error_log('Found identifier through first regex: ' . $matches[1]);
        return $matches[1];
    }

    // If no identifier found, generate a timestamp-based one
    $generatedId = 'EXC' . time();
    error_log('No identifier found. Generated ID: ' . $generatedId);
    // log the id
    error_log('Generated ID: ' . $generatedId, 3, OMEKA_PATH . '/logs/excavation-debug-final.log');
    return $generatedId;
}

private function processOmekaS(string $ttlData, ?int $itemSetId): string
{
    $omekaData = $this->transformTtlToOmekaSData($ttlData, $itemSetId); // Pass itemSetId
    error_log('Omeka Data: ' . print_r($omekaData, true), 3, OMEKA_PATH . '/logs/omeka-data-2.log');
    $omekaErrors = $this->sendToOmekaS($omekaData);
    return implode('; ', $omekaErrors);
}


private function validateUploadType(string $ttlData, ?string $uploadType): void
{
    if (!$uploadType) {
        return; // No upload type specified, skip validation
    }

    // Check for both prefixed and full URI forms
    $isExcavation = (strpos($ttlData, 'crmarchaeo:A9_Archaeological_Excavation') !== false) || 
                    (strpos($ttlData, '<http://www.cidoc-crm.org/extensions/crmarchaeo/A9_Archaeological_Excavation>') !== false);
    
    if ($uploadType === 'excavation' && !$isExcavation) {
        throw new \Exception('Invalid data type for excavation upload.');
    } elseif ($uploadType === 'arrowhead' && $isExcavation) {
        throw new \Exception('Invalid data type for Arrowhead upload.');
    }
}

    public function xmlParser($file)
    {
        // Determine which XSLT to use based on the file content
        $xmlContent = file_get_contents($file['tmp_name']);
        if (strpos($xmlContent, '<item id="AH') !== false) {
            $xsltPath = OMEKA_PATH . '/modules/AddTriplestore/asset/xlst/xlst.xml'; // Arrowhead XSLT
        } elseif (strpos($xmlContent, '<Excavation') !== false) {
            $xsltPath = OMEKA_PATH . '/modules/AddTriplestore/asset/xlst/excavationXlst.xml'; // Excavation XSLT

        } else {
            error_log('Could not determine XML type for XSLT selection.');
            return 'Could not determine XML type'; // Or throw an exception
        }

        // load xsml file
        $xslt = new \DOMDocument();
        // failed to load xsml file
        if (!$xslt->load($xsltPath)) {
            error_log('Failed to load xsml file: ' . $xsltPath);
            return 'Failed to load xsml file';
        }

        // Load the uploaded XML file into a DOMDocument
        $auxFile = new \DOMDocument();
        if (!$auxFile->load($file['tmp_name'])) {
            error_log('Failed to load xml file');
            return 'Failed to load xml file';
        }

        // convert xlm to xlm rdf
        $convert = new \XSLTProcessor();
        $convert->importStylesheet($xslt);
        $rdfXmlConverted = $convert->transformToXML($auxFile);

        // check if conversion fail
        if (!$rdfXmlConverted) {
            error_log('Failed to convert xml to rdf xml');
            return 'Failed to convert xml to rdf xml';
        }

        error_log($rdfXmlConverted, 3, OMEKA_PATH . '/logs/rdf-xml-finsal.log');
        return $rdfXmlConverted;
    }

    public function xmlTtlConverter($rdfXmlData)
{
    error_log('Converting RDF-XML to TTL');

    $rdfGraph = new Graph();
    $rdfGraph->parse($rdfXmlData, 'rdfxml');

    error_log('RDF-XML data loaded into graph');

    $ttlData = $rdfGraph->serialise('turtle');

    error_log('RDF-XML data converted to TTL');

    $ttlData = $this->addPrefixesToTTL($ttlData, [
        'ah' => 'http://www.purl.com/ah/ms/ahMS#',
        'ah-shape' => 'http://www.purl.com/ah/kos/ah-shape/',
        'ah-variant' => 'http://www.purl.com/ah/kos/ah-variant/',
        'ah-base' => 'http://www.purl.com/ah/kos/ah-base/',
        'ah-chippingMode' => 'http://www.purl.com/ah/kos/ah-chippingMode/',
        'ah-chippingDirection' => 'http://www.purl.com/ah/kos/ah-chippingDirection/',
        'ah-chippingDelineation' => 'http://www.purl.com/ah/kos/ah-chippingDelineation/',
        'ah-chippingLocation' => 'http://www.purl.com/ah/kos/ah-chippingLocation/',
        'ah-chippingShape' => 'http://www.purl.com/ah/kos/ah-chippingShape/',
        'crm' => 'http://www.cidoc-crm.org/cidoc-crm/',
        'rdf' => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
        'xsd' => 'http://www.w3.org/2001/XMLSchema#',
        'rdfs' => 'http://www.w3.org/2000/01/rdf-schema#',
        'owl' => 'http://www.w3.org/2002/07/owl#',
        'skos' => 'http://www.w3.org/2004/02/skos/core#',
        'dc' => 'http://purl.org/dc/elements/1.1/',
        'dcterms' => 'http://purl.org/dc/terms/',
        'foaf' => 'http://xmlns.com/foaf/0.1/',
        'ah-vocab' => 'http://www.purl.com/ah/kos#',
        'excav' => 'https://purl.org/ah/ms/excavationMS#', // Corrected namespace
        'dct' => 'http://purl.org/dc/terms/',
        'schema' => 'http://schema.org/',
        'voaf' => 'http://purl.org/vocommons/voaf#',
        'vann' => 'http://purl.org/vocab/vann/',
        'dbo' => 'http://dbpedia.org/ontology/',
        'time' => 'http://www.w3.org/2006/time#',
        'edm' => 'http://www.europeana.eu/schemas/edm#',
        'dul' => 'http://www.ontologydesignpatterns.org/ont/dul/DUL.owl#',
        'crmsci' => 'https://cidoc-crm.org/extensions/crmsci/',
        'crmarchaeo' => 'http://www.cidoc-crm.org/extensions/crmarchaeo/',
        'geo' => 'http://www.w3.org/2003/01/geo/wgs84_pos#',
        'sh' => 'http://www.w3.org/ns/shacl#',
    ]);

    
    $ttlData = preg_replace_callback(
        '/time:inXSDYear "(-?\d+)"\^\^xsd:gYear/',
        function($matches) {
            $year = str_replace('-', '', $matches[1]);
            return 'time:inXSDYear "' . $year . '"^^xsd:gYear';
        },
        $ttlData
    );

    // Fix boolean values
    $ttlData = str_replace('"true"', 'true', $ttlData);
    $ttlData = str_replace('"false"', 'false', $ttlData);
    
    $ttlData = preg_replace_callback(
        '/time:inXSDYear "(-?\d+)"\^\^xsd:gYear/',
        function($matches) {
            $year = str_replace('-', '', $matches[1]);
            return 'time:inXSDYear "' . $year . '"^^xsd:gYear';
        },
        $ttlData
    );

    // Fix the instant URIs to match what the SHACL shapes expect
    //$ttlData = str_replace('excav:Instant_LowerBound_', 'excav:Instant_Lower_', $ttlData);
    //$ttlData = str_replace('excav:Instant_UpperBound_',  'excav:Instant_Upper_', $ttlData);

    
    // Determine if this is excavation data
    if (strpos($ttlData, 'crmarchaeo:A9_Archaeological_Excavation') !== false) {
        $patterns = [
            '/<http:\/\/www\.cidoc-crm\.org\/extensions\/crmarchaeo\/A9_Archaeological_Excavation>/' => 'crmarchaeo:A9_Archaeological_Excavation',
            '/<http:\/\/www\.cidoc-crm\.org\/extensions\/crmarchaeo\/A1_Excavation_Processing_Unit>/' => 'crmarchaeo:A1_Excavation_Processing_Unit',
            '/<http:\/\/www\.cidoc-crm\.org\/extensions\/crmarchaeo\/A2_Stratigraphic_Volume_Unit>/' => 'crmarchaeo:A2_Stratigraphic_Volume_Unit',
            '/<dcterms:identifier>([^<]+)<\/dcterms:identifier>/' => 'dcterms:identifier "$1";',
            '/<dul:hasLocation rdf:resource="([^"]+)"\/>/' => 'dul:hasLocation <$1>;',
            '/<crmarchaeo:A9_Archaeological_Excavation rdf:about="([^"]+)"\/>/' => 'crmarchaeo:A9_Archaeological_Excavation <$1>;',
            '/<excav:ArchaeologistShape rdf:resource="([^"]+)"\/>/' => 'excav:ArchaeologistShape <$1>;',
            '/<excav:hasContext rdf:resource="([^"]+)"\/>/' => 'excav:hasContext <$1>;',
            '/foaf:account "([^"]+)"/' => 'foaf:account "$1"^^xsd:anyURI;',
            '/<foaf:name>([^<]+)<\/foaf:name>/' => 'foaf:name "$1";',
            '/foaf:mbox "([^"]+)"/' => 'foaf:mbox "$1"^^xsd:anyURI',
            '/<excav:hasSVU rdf:resource="([^"]+)"\/>/' => 'excav:hasSVU <$1>;',
            '/<dcterms:description>([^<]+)<\/dcterms:description>/' => 'dcterms:description "$1";',
            '/<excav:hasTimeLine rdf:resource="([^"]+)"\/>/' => 'excav:hasTimeLine <$1>;',
            '/<dbo:informationName>([^<]+)<\/dbo:informationName>/' => 'dbo:informationName "$1";',
            '/excav:Archaeologist /' => 'a excav:Archaeologist;',
            '/excav:excavation_/' => 'a excav:Excavation;',
            '/<excav:foundInAContext rdf:resource="([^"]+)"\/>/' => 'excav:foundInAContext <$1>;',
            '/<excav:hasGPSCoordinates rdf:resource="([^"]+)"\/>/' => 'excav:hasGPSCoordinates <$1>;',
            '/<geo:lat rdf:datatype="[^"]+">([^<]+)<\/geo:lat>/' => 'geo:lat "$1"^^xsd:decimal;',
            '/<geo:long rdf:datatype="[^"]+">([^<]+)<\/geo:long>/' => 'geo:long "$1"^^xsd:decimal;',
            '/<time:hasBeginning rdf:resource="([^"]+)"\/>/' => 'time:hasBeginning <$1>;',
            '/<time:hasEnd rdf:resource="([^"]+)"\/>/' => 'time:hasEnd <$1>;',
            '/<time:inXSDYear rdf:datatype="[^"]+">([^<]+)<\/time:inXSDYear>/' => 'time:inXSDYear "$1"^^xsd:gYear;',
            '/<excav:bc rdf:datatype="[^"]+">([^<]+)<\/excav:bc>/' => 'excav:bc $1;',
            '/<dcterms:date rdf:datatype="[^"]+">([^<]+)<\/dcterms:date>/' => 'dcterms:date "$1"^^xsd:date;',
            '/<dbo:depth rdf:datatype="[^"]+">([^<]+)<\/dbo:depth>/' => 'dbo:depth "$1"^^xsd:decimal;',
            '/<dbo:district rdf:resource="([^"]+)"\/>/' => 'dbo:district <$1>;',
            '/<dbo:parish rdf:resource="([^"]+)"\/>/' => 'dbo:parish <$1>;',
            '/\s*rdf:about="([^"]+)"/' => '',
            '/\s*rdf:resource="([^"]+)"/' => '',
            '/\s*rdf:datatype="[^"]+"/' => '',
            '/<\?xml[^>]+\?>/' => '',
            '/<rdf:RDF[^>]*>/' => '',
            '/<\/rdf:RDF>/' => '',
        ];
    } else {
        $patterns = [
            '/<ah:shape>([^<]+)<\/ah:shape>/' => 'ah:shape <ah-shape:$1>;',
            '/<ah:variant>([^<]+)<\/ah:variant>/' => 'ah:variant <ah-variant:$1>;',
            '/<crm:E57_Material>([^<]+)<\/crm:E57_Material>/' => 'crm:E57_Material <$1>;',
            '/<ah:foundInCoordinates rdf:resource="([^"]+)"\/>/' => 'ah:foundInCoordinates <$1>;',
            '/<ah:hasMorphology rdf:resource="([^"]+)"\/>/' => 'ah:hasMorphology <$1>;',
            '/<ah:hasTypometry rdf:resource="([^"]+)"\/>/' => 'ah:hasTypometry <$1>;',
            '/<ah:point>([^<]+)<\/ah:point>/' => 'ah:point "$1";',
            '/<ah:body>([^<]+)<\/ah:body>/' => 'ah:body "$1";',
            '/<ah:base>([^<]+)<\/ah:base>/' => 'ah:base <ah-base:$1>;',
            '/<crm:E54_Dimension>([^<]+)<\/crm:E54_Dimension>/' => 'crm:E54_Dimension "$1"^^xsd:decimal;',
            '/<ah:hasChipping rdf:resource="([^"]+)"\/>/' => 'ah:hasChipping <$1>;',
            '/<ah:mode>([^<]+)<\/ah:mode>/' => 'ah:mode <ah-chippingMode:$1>;',
            '/<ah:amplitude>([^<]+)<\/ah:amplitude>/' => 'ah:amplitude "$1";',
            '/<ah:direction>([^<]+)<\/ah:direction>/' => 'ah:direction <ah-chippingDirection:$1>;',
            '/<ah:orientation>([^<]+)<\/ah:orientation>/' => 'ah:orientation "$1";',
            '/<ah:dileneation>([^<]+)<\/ah:dileneation>/' => 'ah:dileneation <ah-chippingDelineation:$1>;',
            '/<ah:chippinglocation-Lateral>([^<]+)<\/ah:chippinglocation-Lateral>/' => 'ah:chippinglocation-Lateral <ah-chippingLocation:$1>;',
            '/<ah:chippingLocation-Transversal>([^<]+)<\/ah:chippingLocation-Transversal>/' => 'ah:chippingLocation-Transversal <ah-chippingLocation:$1>;',
            '/<ah:chippingShape>([^<]+)<\/ah:chippingShape>/' => 'ah:chippingShape <ah-chippingShape:$1>;',
            '/<dcterms:identifier>([^<]+)<\/dcterms:identifier>/' => 'dcterms:identifier "$1";',
            '/<edm:Webresource>([^<]+)<\/edm:Webresource>/' => 'edm:Webresource <$1>;',
            '/<dbo:Annotation>([^<]+)<\/dbo:Annotation>/' => 'dbo:Annotation "$1";',
            '/<crm:E3_Condition_State>([^<]+)<\/crm:E3_Condition_State>/' => 'crm:E3_Condition_State "$1";',
            '/<crm:E55_Type>([^<]+)<\/crm:E55_Type>/' => 'crm:E55_Type "$1";',
            '/<geo:lat>([^<]+)<\/geo:lat>/' => 'geo:lat "$1"^^xsd:decimal;',
            '/<geo:long>([^<]+)<\/geo:long>/' => 'geo:long "$1"^^xsd:decimal;',
        ];
    }
    foreach ($patterns as $pattern => $replacement) {
        $ttlData = preg_replace($pattern, $replacement, $ttlData);
    }


    // Clean up any empty lines or extra spaces
    $ttlData = preg_replace("/\n\s*\n/", "\n", $ttlData);
    $ttlData = trim($ttlData);

     // Fix any remaining issues
     $ttlData = str_replace('ns0:', 'dul:', $ttlData);
     $ttlData = str_replace('ns1:', 'excav:', $ttlData);
     $ttlData = str_replace('ns2:', 'dbo:', $ttlData);
     $ttlData = str_replace('ns3:', 'crmsci:', $ttlData);

    error_log("Cleaned TTL: " . $ttlData, 3, OMEKA_PATH . '/logs/cleaned-ttl.log');
    return $ttlData;
}

    private function addPrefixesToTTL($ttlData, $prefixes)
    {
        $prefixLines = '';
        foreach ($prefixes as $prefix => $iri) {
            $prefixLines .= "@prefix $prefix: <$iri>.\n";
            // log here
            error_log("Adding prefix: $prefix: <$iri>");
        }
        return $prefixLines . $ttlData;
    }

    private function sendToGraphDB($data, $excavationId = null)
{
    $logger = new Logger();
    $writer = new Stream(OMEKA_PATH . '/logs/graphdb-errors.log');
    $logger->addWriter($writer);

    // Set the graph URI based on excavation ID if provided
    $graphUri = $this->baseDataGraphUri;
    
    // Use the provided excavation ID or default to "0"
    $this->excavationIdentifier = $excavationId ?: "0";
    $graphUri .= $this->excavationIdentifier . "/";
    
    error_log('Using graph: ' . $graphUri, 3, OMEKA_PATH . '/logs/excavation-debug.log');

    try {
        $validationResult = $this->validateData($data, $graphUri);
        // log the validation result
        error_log('Validation Result: ' . implode('; ', $validationResult), 3, OMEKA_PATH . '/logs/validation-log.log');

        if (!empty($validationResult)) {
            $errorMessage = 'Data upload failed: SHACL validation errors: ' . implode('; ', $validationResult);
            error_log($errorMessage, 3, OMEKA_PATH . '/logs/graphdb-errors.log');
            $logger->err($errorMessage);
            return $errorMessage;
        }

        // 2. Upload ONLY if validation passes
        $client = new Client();
        $fullUrl = $this->graphdbEndpoint . '?graph=' . urlencode($graphUri);
        error_log('Uploading to graph: ' . $fullUrl, 3, OMEKA_PATH . '/logs/graphdb-upload.log');
        
        $client->setUri($fullUrl);
        $client->setMethod('POST');
        $client->setHeaders(['Content-Type' => 'text/turtle']);
        $client->setRawBody($data);

        $client->setOptions(['timeout' => 60]); // Adjust the timeout as needed

        $response = $client->send();

        $status = $response->getStatusCode();
        $body = $response->getBody();
        $message = "Response Status: $status | Response Body: $body";
        error_log($message, 3, OMEKA_PATH . '/logs/graphdb-response.log');
        $logger->info($message);

        if ($response->isSuccess()) {
            return 'Data uploaded and validated successfully.';
        } else {
            $errorMessage = 'Failed to upload data: ' . $message;
            error_log($errorMessage, 3, OMEKA_PATH . '/logs/graphdb-errors.log');
            $logger->err($errorMessage);
            return $errorMessage;
        }
    } catch (\Exception $e) {
        $errorMessage = 'Failed to upload data due to an exception: ' . $e->getMessage();
        $logger->err($errorMessage);
        error_log($errorMessage, 3, OMEKA_PATH . '/logs/graphdb-errors.log');
        return $errorMessage;
    }
}


    private function validateData($data, $graphUri)
    {
        $errors = [];
        $logger = new Logger(); // Initialize logger here
        $writer = new Stream(OMEKA_PATH . '/logs/graphdb-errors.log');
        $logger->addWriter($writer);

        try {
            // 1. Prepare the validation query
            $query = "PREFIX sh: <http://www.w3.org/ns/shacl#>
            PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
            
            SELECT ?message
            WHERE {
              GRAPH <http://www.arch-project.com/shapes> {
                ?shape a sh:NodeShape .
              }
              GRAPH <http://www.arch-project.com/data> {
                ?focusNode ?predicate ?object .
              }
              FILTER EXISTS {
                  GRAPH <http://www.arch-project.com/shapes> {
                    ?shape sh:targetClass ?targetClass .
                    FILTER NOT EXISTS { ?focusNode a ?targetClass }
                  }
              }
              FILTER EXISTS {
                  GRAPH <http://www.arch-project.com/shapes> {
                    ?shape sh:property ?propertyShape .
                    ?propertyShape sh:path ?path .
                    FILTER NOT EXISTS { ?focusNode ?path ?object }
                  }
              }
              BIND(CONCAT('Violation at node: ', str(?focusNode), ', predicate: ', str(?predicate), ', object: ', str(?object)) AS ?message)
            }
            ";

            // 2. Execute the validation query
            $client = new Client();
            $client->setUri($this->graphdbQueryEndpoint);
            $client->setMethod('POST');
            $client->setHeaders([
                'Content-Type' => 'application/sparql-query',
                'Accept' => 'application/sparql-results+json' // Crucial: Request JSON results
            ]);
            $client->setRawBody($query);
            $response = $client->send();

            if (!$response->isSuccess()) {
                $errorMessage = "SHACL validation query failed: " . $response->getStatusCode() . " - " . $response->getBody();
                $logger->err($errorMessage);
                error_log($errorMessage);
                return [$errorMessage];
            }

            $rawBody = $response->getBody();
            error_log("Raw GraphDB Response: " . $rawBody); // Keep logging the raw response

            $results = json_decode($rawBody, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $errorMessage = "Error decoding JSON response: " . json_last_error_msg() . " Raw Body: " . $rawBody; // Include raw body in error
                $logger->err($errorMessage);
                error_log($errorMessage);
                return [$errorMessage];
            }

            if (isset($results['results']['bindings'])) {
                foreach ($results['results']['bindings'] as $binding) {
                    $errors[] = $binding['message']['value'];
                }
            }
        } catch (\Exception $e) {
            $errorMessage = 'SHACL validation failed due to an exception: ' . $e->getMessage();
            $logger->err($errorMessage);
            error_log($errorMessage);
            return [$errorMessage];
        }

        return $errors;
    }



private function getExcavationIdentifierFromItemSet($itemSetId)
{
    // Get mappings from site settings
    $mappings = $this->siteSettings()->get('excavation_itemset_mappings', []);
    
    if (isset($mappings[$itemSetId])) {
        return $mappings[$itemSetId];
    }
    
    // If no mapping found, try to extract from item set title
    try {
        $itemSet = $this->api()->read('item_sets', $itemSetId)->getContent();
        $title = $itemSet->displayTitle();
        
        // Parse the title to extract excavation ID if it follows our pattern
        if (preg_match('/Excavation\s+([^\s]+)/', $title, $matches)) {
            $excavationId = $matches[1];
            
            // Store this mapping for future use
            $this->storeMappingBetweenItemSetAndExcavation($itemSetId, $excavationId);
            
            return $excavationId;
        }
    } catch (\Exception $e) {
        error_log('Error retrieving item set: ' . $e->getMessage(), 3, OMEKA_PATH . '/logs/excavation-debug.log');
    }
    
    return null;
}

/**
 * Modify the transformTtlToOmekaSData method to include excavation context
 * This method transforms TTL data to Omeka S item data format
 */
private function transformTtlToOmekaSData($ttlData, $itemSetId = null): array {
    $graph = new \EasyRdf\Graph();
    $graph->parse($ttlData, 'turtle');
    
    $omekaData = [];
    $rdfData = $graph->toRdfPhp();
    
    // Find subjects (arrowheads, excavation components, etc.)
    $subjects = [];
    foreach ($rdfData as $subject => $predicates) {
        foreach ($predicates as $predicate => $objects) {
            foreach ($objects as $object) {
                // Look for various types of archaeological objects
                if ($predicate === 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type' && $object['type'] === 'uri') {
                    // E24_Physical_Man-Made_Thing (arrowheads)
                    if ($object['value'] === 'http://www.cidoc-crm.org/cidoc-crm/E24_Physical_Man-Made_Thing') {
                        $subjects[] = $subject;
                    }
                    // A9_Archaeological_Excavation (excavations)
                    else if ($object['value'] === 'http://www.cidoc-crm.org/extensions/crmarchaeo/A9_Archaeological_Excavation') {
                        $subjects[] = $subject;
                    }
                    // A1_Excavation_Processing_Unit (contexts)
                    else if ($object['value'] === 'http://www.cidoc-crm.org/extensions/crmarchaeo/A1_Excavation_Processing_Unit') {
                        $subjects[] = $subject;
                    }
                    // A2_Stratigraphic_Volume_Unit (SVUs)
                    else if ($object['value'] === 'http://www.cidoc-crm.org/extensions/crmarchaeo/A2_Stratigraphic_Volume_Unit') {
                        $subjects[] = $subject;
                    }
                    // S19_Encounter_Event (encounter events)
                    else if ($object['value'] === 'https://cidoc-crm.org/extensions/crmsci/S19_Encounter_Event') {
                        $subjects[] = $subject;
                    }
                }
            }
        }
    }
    
    // Get excavation identifier for context - use either the default or from item set
    $excavationId = "0"; // Default
    if ($itemSetId) {
        $mappedId = $this->getExcavationIdentifierFromItemSet($itemSetId);
        if ($mappedId) {
            $excavationId = $mappedId;
        }
    }
    
    // Process each subject as a single item
    foreach ($subjects as $subject) {
        $itemData = [
            'o:resource_class' => ['o:id' => 1], // Default Item Resource Class ID
            'o:item_set' => [],                  // Will be populated if itemSetId exists
        ];
        
        // Add item to item set if provided
        if ($itemSetId) {
            $itemData['o:item_set'][] = ['o:id' => $itemSetId];
        }
        
        // Process the main properties
        $this->processSubjectProperties($rdfData, $subject, $itemData);
        
        // Add excavation context as metadata
        if ($excavationId != "0") {
            if (!isset($itemData['dcterms:isPartOf'])) {
                $itemData['dcterms:isPartOf'] = [];
            }
            
            $itemData['dcterms:isPartOf'][] = [
                'type' => 'literal',
                'property_id' => 40, // isPartOf property ID in Omeka S
                '@value' => "Excavation $excavationId"
            ];
        }
        
        // Extract identifier for the title
        $identifier = null;
        if (isset($itemData['dcterms:identifier'])) {
            foreach ($itemData['dcterms:identifier'] as $identifierValue) {
                if (isset($identifierValue['@value'])) {
                    $identifier = $identifierValue['@value'];
                    break;
                }
            }
        }
        
        // Set a proper title in Dublin Core terms
        if ($identifier) {
            $itemType = $this->determineItemType($rdfData, $subject);
            
            $title = $itemType . " " . $identifier;
            if ($excavationId != "0") {
                $title .= " (Excavation $excavationId)";
            }
            
            $itemData['dcterms:title'] = [
                [
                    'type' => 'literal',
                    'property_id' => 1, // dcterms:title property ID in Omeka
                    '@value' => $title
                ]
            ];
        } else {
            // Extract subject ID as fallback
            $parts = explode('/', $subject);
            $lastPart = end($parts);
            
            $itemData['dcterms:title'] = [
                [
                    'type' => 'literal',
                    'property_id' => 1, // dcterms:title property ID in Omeka
                    '@value' => "Archaeological Item $lastPart"
                ]
            ];
        }
        
        // Now process related subjects (morphology, typometry, chipping, coordinates)
        $this->processRelatedSubjects($rdfData, $subject, $itemData);
        
        $omekaData[] = $itemData;
    }
    
    return $omekaData;
}

    
    private function processSubjectProperties($rdfData, $subject, &$itemData) {
        if (!isset($rdfData[$subject])) {
            return;
        }
        
        foreach ($rdfData[$subject] as $predicate => $objects) {
            $propertyId = $this->getOmekaPropertyId($predicate);
            
            if ($propertyId) {
                if (!isset($itemData[$predicate])) {
                    $itemData[$predicate] = [];
                }
                
                foreach ($objects as $object) {
                    $value = null;
                    
                    if ($object['type'] === 'literal') {
                        $value = [
                            'type' => 'literal',
                            'property_id' => $propertyId,
                            '@value' => $object['value'],
                        ];
                        if (isset($object['datatype'])) {
                            $value['@type'] = $object['datatype'];
                        }
                        if (isset($object['lang'])) {
                            $value['@language'] = $object['lang'];
                        }
                    } elseif ($object['type'] === 'uri') {
                        // Don't include references to other subjects we'll process separately
                        if (isset($rdfData[$object['value']])) {
                            continue;
                        }
                        
                        // Handle special cases for vocabulary terms
                        if (strpos($object['value'], 'http://www.purl.com/ah/kos/') === 0) {
                            // Extract the term from the URI
                            $parts = explode('/', $object['value']);
                            $term = end($parts);
                            
                            $value = [
                                'type' => 'literal',
                                'property_id' => $propertyId,
                                '@value' => $term,
                            ];
                        } else {
                            $value = [
                                'type' => 'resource',
                                'property_id' => $propertyId,
                                '@id' => $object['value'],
                            ];
                        }
                    }
                    
                    if ($value !== null) {
                        $itemData[$predicate][] = $value;
                    }
                }
            }
        }
    }
    
    private function processRelatedSubjects($rdfData, $mainSubject, &$itemData) {
        if (!isset($rdfData[$mainSubject])) {
            return;
        }
        
        // Find related subjects
        $relatedSubjects = [];
        
        foreach ($rdfData[$mainSubject] as $predicate => $objects) {
            foreach ($objects as $object) {
                if ($object['type'] === 'uri' && isset($rdfData[$object['value']])) {
                    $relatedSubjects[$predicate] = $object['value'];
                }
            }
        }
        
        // Process each related subject
        foreach ($relatedSubjects as $relation => $subject) {
            // Record the property connecting this subject to the main arrowhead
            $propertyId = $this->getOmekaPropertyId($relation);
            if ($propertyId) {
                if (!isset($itemData[$relation])) {
                    $itemData[$relation] = [];
                }
                
                // Now get all properties of the related subject
                $this->processSubjectProperties($rdfData, $subject, $itemData);
                
                // Recursively process any subjects related to this one
                $this->processRelatedSubjects($rdfData, $subject, $itemData);
            }
        }
    }


    private function getOmekaPropertyId($omekaProperty) {
        $propertyIds = [
            'http://purl.org/dc/terms/identifier' => 10,  // dcterms:identifier
            'http://www.europeana.eu/schemas/edm#Webresource' => 100, // Replace with actual ID
            'http://www.purl.com/ah/kos/ah-shape/' => 7460, // Replace with actual ID
            'http://www.cidoc-crm.org/cidoc-crm/P45_consists_of' => 478, // Replace with actual ID
            'http://dbpedia.org/ontology/Annotation' => 57, // Replace with actual ID
            'http://www.cidoc-crm.org/cidoc-crm/E3_Condition_State' => 476, // Replace with actual ID
            'http://www.cidoc-crm.org/cidoc-crm/E55_Type' => 399, // Replace with actual ID 
            'http://www.purl.com/ah/ms/ahMS#variant' => 7461, // Replace with actual ID
            'http://www.purl.com/ah/ms/ahMS#foundInCoordinates' => 7456, // Replace with actual ID
            'http://www.purl.com/ah/ms/ahMS#hasMorphology' => 7457, // Replace with actual ID
            'http://www.purl.com/ah/ms/ahMS#hasTypometry' => 7458, // Replace with actual ID
            'http://www.purl.com/ah/ms/ahMS#point' => 7462, // Replace with actual ID
            'http://www.purl.com/ah/ms/ahMS#body' => 7463, // Replace with actual ID
            'http://www.purl.com/ah/ms/ahMS#base' => 7464, // Replace with actual ID
            'http://www.cidoc-crm.org/cidoc-crm/E54_Dimension' => 474, // Replace with actual ID
            'http://www.purl.com/ah/ms/ahMS#hasChipping' => 7459, // Replace with actual ID
            'http://www.purl.com/ah/ms/ahMS#mode' => 7465, // Replace with actual ID
            'http://www.purl.com/ah/ms/ahMS#amplitude' => 7466, // Replace with actual ID
            'http://www.purl.com/ah/ms/ahMS#direction' => 7467, // Replace with actual ID
            'http://www.purl.com/ah/ms/ahMS#orientation' => 7468, // Replace with actual ID
            'http://www.purl.com/ah/ms/ahMS#delineation' => 7469, // Replace with actual ID
            'http://www.purl.com/ah/ms/ahMS#chippinglocation-Lateral' => 7470, // Replace with actual ID
            'http://www.purl.com/ah/ms/ahMS#chippingLocation-Transversal' => 7471, // Replace with actual ID
            'http://www.purl.com/ah/ms/ahMS#chippingShape' => 7472, // Replace with actual ID
            'http://www.w3.org/2003/01/geo/wgs84_pos#lat' => 257, // Replace with actual ID
            'http://www.w3.org/2003/01/geo/wgs84_pos#long' => 259, // Replace with actual ID
        ];
        
        return $propertyIds[$omekaProperty] ?? null;
    }
    

    private function sendToOmekaS($omekaData) {
        $omekaBaseUrl = 'http://localhost/api';
        $omekaKeyIdentity = '2TGK0xT9tEMCUQs1178OyCnyRcIQpv5B';
        $omekaKeyCredential = '9IFd207Y8D5yG1bmtnCllmbgZweuMfQA';
        $omekaUser = 1;
    
        $client = new Client();
        $client->setMethod('POST');
        $client->setHeaders([
            'Content-Type' => 'application/json',
            'Omeka-S-Api-Key' => $omekaUser,
        ]);
    
        $errors = [];
        $createdItems = [];
        
        foreach ($omekaData as $itemIndex => $itemData) {
            $fullUrl = rtrim($omekaBaseUrl, '/') . '/items' . 
                       '?key_identity=' . urlencode($omekaKeyIdentity) .
                       '&key_credential=' . urlencode($omekaKeyCredential);
            
            $client->setUri($fullUrl);
            $client->setRawBody(json_encode($itemData));
            $response = $client->send();
    
            if (!$response->isSuccess()) {
                $errors[] = 'Failed to create item ' . ($itemIndex + 1) . ': ' . 
                             $response->getStatusCode() . ' - ' . $response->getBody();
                error_log('Omeka S API Error: ' . $response->getBody());
            } else {
                $createdItems[] = json_decode($response->getBody(), true);
                error_log('Omeka S Item Created Successfully: ID=' . 
                           json_decode($response->getBody(), true)['o:id']);
            }
        }
    
        return [
            'errors' => $errors,
            'created_items' => $createdItems
        ];
    }
}