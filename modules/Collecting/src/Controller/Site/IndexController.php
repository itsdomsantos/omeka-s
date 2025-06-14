<?php
namespace Collecting\Controller\Site;

use Collecting\Api\Representation\CollectingFormRepresentation;
use Collecting\MediaType\Manager;
use Omeka\Permissions\Acl;
use Laminas\Mime\Message as MimeMessage;
use Laminas\Mime\Part as MimePart;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

class IndexController extends AbstractActionController
{
    /**
     * @var Acl
     */
    protected $acl;

    protected $mediaTypeManager;

    private $api;


    public function __construct(Acl $acl, Manager $mediaTypeManager)
    {
        $this->acl = $acl;
        $this->mediaTypeManager = $mediaTypeManager;
    }


public function uploadArrowheadFormAction()
{
    $formId = 4;
    $cForm = $this->api()->read('collecting_forms', $formId)->getContent();
    $form = $cForm->getForm();
    
    // Get the item set ID from query parameters
    $itemSetId = $this->params()->fromQuery('item_set_id');
    $uploadType = $this->params()->fromQuery('upload_type', 'arrowhead');
    $returnUrl = $this->params()->fromQuery('return_url');
    
    // Fetch squares, contexts, and SVUs from this item set
    $squares = [];
    $contexts = [];
    $svus = [];
    
    if ($itemSetId) {
        // Fetch all items in this item set
        $items = $this->api()->search('items', ['item_set_id' => $itemSetId])->getContent();
        
        foreach ($items as $item) {
            // Get item type from title or class
            $title = $item->displayTitle();
            $values = $item->values();
            
            // FIXED: First check if this is an encounter event and skip it
            if (strpos($title, 'Encounter Event') !== false || 
                strpos($title, 'Archaeological Encounter') !== false ||
                $this->hasProperty($values, 'Encounter Date') ||
                $this->hasProperty($values, 'Encountered Objects')) {
                continue; // Skip encounter events
            }
            
            // Check if this is a Square
            if ((strpos($title, 'Square') !== false && strpos($title, 'Encounter') === false) ||
                $this->hasProperty($values, 'Square ID')) {
                $squares[] = [
                    'id' => $item->id(),
                    'label' => $title,
                    'identifier' => $this->getPropertyValue($values, 'Square ID')
                ];
            }
            
            // Check if this is a Context
            if ((strpos($title, 'Context') !== false && strpos($title, 'Encounter') === false) ||
                $this->hasProperty($values, 'Context ID')) {
                $contexts[] = [
                    'id' => $item->id(),
                    'label' => $title,
                    'identifier' => $this->getPropertyValue($values, 'Context ID')
                ];
            }
            
            // FIXED: More specific check for Stratigraphic Volume Units
            if (((strpos($title, 'Stratigraphic Unit') !== false || 
                  strpos($title, 'Stratigraphic Volume Unit') !== false ||
                  (strpos($title, 'SVU') !== false && strpos($title, 'Encounter') === false)) &&
                 strpos($title, 'Encounter Event') === false) ||
                $this->hasProperty($values, 'SVU ID')) {
                $svus[] = [
                    'id' => $item->id(),
                    'label' => $title,
                    'identifier' => $this->getPropertyValue($values, 'SVU ID')
                ];
            }
        }
    }
    
    // If returnUrl is provided, override the form action
    if ($returnUrl) {
        $form->setAttribute('action', $this->url()->fromRoute('site/add-triplestore/process-collecting', [
            'site-slug' => $this->currentSite()->slug(),
        ], [
            'query' => [
                'item_set_id' => $itemSetId,
                'upload_type' => $uploadType
            ]
        ]));
    }
    
    $result = $this->params()->fromQuery('result', '');
    
    $view = new ViewModel([
        'form' => $form,
        'formType' => 'arrowhead',
        'itemSetId' => $itemSetId,
        'result' => $result,
        'squares' => $squares,
        'contexts' => $contexts,
        'svus' => $svus
    ]);
    
    return $view;
}
    
/**
 * Helper to check if an item has a specific property
 */
private function hasProperty($values, $propertyLabel)
{
    if (empty($values)) {
        return false;
    }
    
    foreach ($values as $propertyValues) {
        // Make sure we have at least one value
        if (!isset($propertyValues[0])) {
            continue;
        }
        
        // Get the property
        $property = $propertyValues[0]->property();
        if (!$property) {
            continue;
        }
        
        // Check if this is the property we're looking for
        if ($property->label() === $propertyLabel) {
            return true;
        }
    }
    return false;
}

/**
 * Helper to get a property value
 */
private function getPropertyValue($values, $propertyLabel)
{
    if (empty($values)) {
        return null;
    }
    
    foreach ($values as $propertyValues) {
        // Make sure we have at least one value
        if (!isset($propertyValues[0])) {
            continue;
        }
        
        // Get the property
        $property = $propertyValues[0]->property();
        if (!$property) {
            continue;
        }
        
        // Check if this is the property we're looking for
        if ($property->label() === $propertyLabel) {
            return $propertyValues[0]->value();
        }
    }
    return null;
}



    public function submitArrowheadAction()
{
    $formId = $this->params('form-id');
    $cForm = $this->api()->read('collecting_forms', $formId)->getContent();
    $form = $cForm->getForm();
    $form->setData($this->params()->fromPost());
    
    // Get the item set ID from the query parameters
    $itemSetId = $this->params()->fromQuery('item_set_id');
    
    if ($form->isValid()) {
        $arrowheadData = $this->getFormData($cForm);
        
        // Process and save the arrowhead data
        $ttlData = $this->processArrowheadFormData($arrowheadData, $itemSetId);
        $result = $this->uploadTtlData($ttlData, $itemSetId);
        
        // Redirect to a success page with minimal data in the URL
        $url = $this->url('site/collecting', [
            'site-slug' => $this->currentSite()->slug(),
            'form-id' => $formId,
            'action' => 'uploadArrowheadForm'
        ], [
            'query' => [
                'result' => $result,
                'item_set_id' => $itemSetId,
                'success' => '1' // Add a success flag
            ]
        ]);
        
        return $this->redirect()->toUrl($url);
    } else {
        $this->messenger()->addErrors($form->getMessages());
        return $this->redirect()->toRoute('site/collecting', [
            'form-id' => $formId, 
            'action' => 'uploadArrowheadForm'
        ]);
    }
}
    
    private function getExcavationIdentifierFromItemSet($itemSetId)
    {
        // Get mappings from site settings
        $mappings = $this->siteSettings()->get('excavation_itemset_mappings', []);
        
        if (isset($mappings[$itemSetId])) {
            return $mappings[$itemSetId];
        }
        
        return null;
    }

    /**
 * Fetch entities of a specific type from the triple store
 * 
 * @param string $entityType The type of entity to fetch (Context, SVU, EncounterEvent)
 * @return array List of entities with their IDs and names
 */
private function fetchEntitiesFromTripleStore($entityType)
{
    // Base SPARQL endpoint
    $endpoint = "http://localhost:7200/repositories/megalod";
    
    // Query pattern depends on entity type
    switch ($entityType) {
        case 'Context':
            $query = "
                PREFIX crmarchaeo: <http://www.cidoc-crm.org/extensions/crmarchaeo/>
                PREFIX dct: <http://purl.org/dc/terms/>
                
                SELECT ?context ?id
                WHERE {
                    ?context a crmarchaeo:A1_Excavation_Processing_Unit ;
                             dct:identifier ?id .
                }
                ORDER BY ?id
            ";
            break;
            
        case 'SVU':
            $query = "
                PREFIX crmarchaeo: <http://www.cidoc-crm.org/extensions/crmarchaeo/>
                PREFIX dct: <http://purl.org/dc/terms/>
                
                SELECT ?svu ?id
                WHERE {
                    ?svu a crmarchaeo:A2_Stratigraphic_Volume_Unit ;
                         dct:identifier ?id .
                }
                ORDER BY ?id
            ";
            break;
            
        case 'EncounterEvent':
            $query = "
                PREFIX crmsci: <https://cidoc-crm.org/extensions/crmsci/>
                PREFIX dct: <http://purl.org/dc/terms/>
                
                SELECT ?event ?date
                WHERE {
                    ?event a crmsci:S19_Encounter_Event ;
                           dct:date ?date .
                }
                ORDER BY ?date
            ";
            break;
            
        default:
            return [];
    }
    
    // Use HTTP client to query the triple store
    $client = new \Laminas\Http\Client();
    $client->setUri($endpoint);
    $client->setMethod('POST');
    $client->setParameterPost([
        'query' => $query,
        'format' => 'application/sparql-results+json'
    ]);
    
    $response = $client->send();
    
    if ($response->isSuccess()) {
        $results = json_decode($response->getBody(), true);
        $entities = [];
        
        // Process results based on entity type
        if (isset($results['results']['bindings'])) {
            foreach ($results['results']['bindings'] as $binding) {
                if ($entityType === 'EncounterEvent') {
                    $entities[] = [
                        'uri' => $binding['event']['value'],
                        'label' => $binding['date']['value']
                    ];
                } else {
                    $entities[] = [
                        'uri' => $binding[strtolower($entityType)]['value'],
                        'label' => $binding['id']['value']
                    ];
                }
            }
        }
        
        return $entities;
    }
    
    return [];
}



private function redirectToTriplestore(array $data, string $uploadType, ?int $itemSetId = null): void
{
    // Get current site slug
    $siteSlug = $this->currentSite()->slug();

    // Determine form ID based on upload type
    $formId = ($uploadType === 'excavation') ? 3 : 1; // Adjust these IDs to match your actual form IDs

    $query = [
        'upload_type' => $uploadType,
        'form_data' => $data
    ];
    
    if ($itemSetId) {
        $query['item_set_id'] = $itemSetId;
    }
    
    $url = $this->url('site/collecting', [
        'site-slug' => $siteSlug,
        'form-id' => $formId,
        'action' => 'uploadExcavationForm'
    ], [
        'query' => $query
    ]);
    
    error_log('Redirecting to: ' . $url);
    
    $this->redirect()->toUrl($url);
}


// Add these methods to your Collecting/src/Controller/Site/IndexController.php

/**
 * Enhanced excavation form upload action with entity management
 */
public function uploadExcavationFormAction()
{
    $formId = 3; // Excavation form ID
    $cForm = $this->api()->read('collecting_forms', $formId)->getContent();
    $form = $cForm->getForm();

    // Fetch existing archaeologists for the dropdown
    $existingArchaeologists = $this->fetchExistingArchaeologists();
    
    $result = $this->params()->fromQuery('result');
    $itemSetId = $this->params()->fromQuery('item_set_id');

    $view = new ViewModel([
        'form' => $form,
        'formType' => 'excavation',
        'existingArchaeologists' => $existingArchaeologists,
        'result' => $result,
        'itemSetId' => $itemSetId
    ]);
    
    return $view;
}

/**
 * Enhanced excavation submission with entity processing
 */
public function submitExcavationAction()
{
    $formId = $this->params('form-id');
    $cForm = $this->api()->read('collecting_forms', $formId)->getContent();
    $form = $cForm->getForm();
    $form->setData($this->params()->fromPost());

    if ($form->isValid()) {
        $rawPost = $this->params()->fromPost();
        error_log('=== RAW POST DATA ===', 3, OMEKA_PATH . '/logs/excavation-debug.log');
        error_log(print_r($rawPost, true), 3, OMEKA_PATH . '/logs/excavation-debug.log');
        
        // STEP 2: Debug collecting form extraction
        $excavationData = $this->getFormData($cForm);
        error_log('=== EXCAVATION DATA FROM getFormData() ===', 3, OMEKA_PATH . '/logs/excavation-debug.log');
        error_log(print_r($excavationData, true), 3, OMEKA_PATH . '/logs/excavation-debug.log');
        
        // STEP 3: Debug entities data
        $entitiesDataJson = $this->params()->fromPost('entities_data', '{}');
        error_log('=== ENTITIES JSON STRING ===', 3, OMEKA_PATH . '/logs/excavation-debug.log');
        error_log('Raw JSON: ' . $entitiesDataJson, 3, OMEKA_PATH . '/logs/excavation-debug.log');
        
        $entitiesData = json_decode($entitiesDataJson, true);
        error_log('=== PARSED ENTITIES DATA ===', 3, OMEKA_PATH . '/logs/excavation-debug.log');
        error_log(print_r($entitiesData, true), 3, OMEKA_PATH . '/logs/excavation-debug.log');
        
        // STEP 4: Debug archaeologist data  
        $archaeologistData = $this->processArchaeologistData();
        error_log('=== ARCHAEOLOGIST DATA ===', 3, OMEKA_PATH . '/logs/excavation-debug.log');
        error_log(print_r($archaeologistData, true), 3, OMEKA_PATH . '/logs/excavation-debug.log');
        
        // STEP 5: Debug excavation identifier
        $excavationIdentifier = $this->params()->fromPost('excavation_id');
        error_log('=== EXCAVATION IDENTIFIER ===', 3, OMEKA_PATH . '/logs/excavation-debug.log');
        error_log('From POST: ' . $excavationIdentifier, 3, OMEKA_PATH . '/logs/excavation-debug.log');
        
        if (empty($excavationIdentifier)) {
            $excavationIdentifier = 'EXC-' . uniqid();
            error_log('Generated: ' . $excavationIdentifier, 3, OMEKA_PATH . '/logs/excavation-debug.log');
        }
        
        // STEP 6: Let's also check what prompts we have
        error_log('=== COLLECTING FORM PROMPTS ===', 3, OMEKA_PATH . '/logs/excavation-debug.log');
        foreach ($cForm->prompts() as $prompt) {
            error_log('Prompt ID: ' . $prompt->id() . ', Type: ' . $prompt->type() . ', Text: ' . $prompt->text(), 3, OMEKA_PATH . '/logs/excavation-debug.log');
        }
        try {
            // Create TTL data with enhanced entities
            $ttlData = $this->prepareUnifiedTtlFromExcavationData(
                $excavationIdentifier,
                $excavationData,
                $entitiesData,
                $archaeologistData
            );

            // Create item set
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
                        '@value' => $this->generateExcavationDescription($excavationData, $entitiesData)
                    ]
                ],
                'o:is_public' => true
            ];

            $itemSetResponse = $this->api()->create('item_sets', $itemSetData);
            $itemSetId = $itemSetResponse->getContent()->id();

            // Store mapping
            $this->storeMappingBetweenItemSetAndExcavation($itemSetId, $excavationIdentifier);

            // Upload TTL data
            $uploadResult = $this->uploadTtlData($ttlData, $itemSetId);

            // Redirect with success
            return $this->redirect()->toUrl($this->url()->fromRoute('site/collecting', [
                'site-slug' => $this->currentSite()->slug(),
                'form-id' => $formId,
                'action' => 'uploadExcavationForm'
            ], [
                'query' => [
                    'result' => $uploadResult,
                    'item_set_id' => $itemSetId
                ]
            ]));

        } catch (\Exception $e) {
            error_log('Enhanced excavation submission error: ' . $e->getMessage());
            
            return $this->redirect()->toUrl($this->url()->fromRoute('site/collecting', [
                'site-slug' => $this->currentSite()->slug(),
                'form-id' => $formId,
                'action' => 'uploadExcavationForm'
            ], [
                'query' => [
                    'result' => 'Error: ' . $e->getMessage()
                ]
            ]));
        }
    }

    $this->messenger()->addErrors($form->getMessages());
    return $this->redirect()->toRoute('site/collecting', [
        'form-id' => $formId, 
        'action' => 'uploadExcavationForm'
    ]);
}

/**
 * Fetch existing archaeologists from both Omeka and triplestore
 */
private function fetchExistingArchaeologists()
{
    $archaeologists = [];
    
    try {
        // Search Omeka items for archaeologists
        $response = $this->api()->search('items', [
            'resource_class_id' => 94, // Person class (adjust as needed)
            'sort_by' => 'title',
            'sort_order' => 'asc'
        ]);
        
        foreach ($response->getContent() as $item) {
            $values = $item->values();
            $name = '';
            $orcid = '';
            $email = '';
            
            // Extract archaeologist data from item values
            foreach ($values as $term => $propertyValues) {
                if (!empty($propertyValues) && isset($propertyValues[0])) {
                    $property = $propertyValues[0]->property();
                    if ($property) {
                        $label = $property->label();
                        $value = $propertyValues[0]->value();
                        
                        if (stripos($label, 'name') !== false) {
                            $name = $value;
                        } elseif (stripos($label, 'orcid') !== false || stripos($label, 'account') !== false) {
                            $orcid = $value;
                        } elseif (stripos($label, 'email') !== false || stripos($label, 'mbox') !== false) {
                            $email = str_replace('mailto:', '', $value);
                        }
                    }
                }
            }
            
            if ($name) {
                $archaeologists[] = [
                    'id' => $item->id(),
                    'name' => $name,
                    'orcid' => $orcid,
                    'email' => $email
                ];
            }
        }
        
    } catch (\Exception $e) {
        error_log('Error fetching archaeologists: ' . $e->getMessage());
    }
    
    return $archaeologists;
}

private function processArchaeologistData()
{
    $rawPost = $this->params()->fromPost();
    error_log('=== ARCHAEOLOGIST PROCESSING DEBUG ===', 3, OMEKA_PATH . '/logs/archaeologist-debug.log');
    error_log('Raw POST for archaeologist: ' . print_r($rawPost, true), 3, OMEKA_PATH . '/logs/archaeologist-debug.log');
    
    $existingId = $this->params()->fromPost('existing_archaeologist');
    error_log('Existing archaeologist ID: ' . $existingId, 3, OMEKA_PATH . '/logs/archaeologist-debug.log');
    
    if ($existingId) {
        // Use existing archaeologist
        try {
            $archaeologist = $this->api()->read('items', $existingId)->getContent();
            return [
                'existing' => true,
                'item_id' => $existingId,
                'uri' => $archaeologist->url()
            ];
        } catch (\Exception $e) {
            error_log('Error loading existing archaeologist: ' . $e->getMessage(), 3, OMEKA_PATH . '/logs/archaeologist-debug.log');
        }
    }
    
    // Create new archaeologist - get data from the basic form prompts
    $name = $this->params()->fromPost('new_archaeologist_name');
    $orcid = $this->params()->fromPost('new_archaeologist_orcid');
    $email = $this->params()->fromPost('new_archaeologist_email');
    
    // ALSO try to get from the collecting form prompts
    if (empty($name)) {
        foreach ($rawPost as $key => $value) {
            if (strpos($key, 'prompt_') === 0 && !empty($value)) {
                // You'll need to identify which prompt corresponds to archaeologist name
                // This will depend on your form setup
                if (stripos($key, 'archaeologist') !== false && stripos($key, 'name') !== false) {
                    $name = $value;
                }
                if (stripos($key, 'orcid') !== false) {
                    $orcid = $value;
                }
                if (stripos($key, 'email') !== false) {
                    $email = $value;
                }
            }
        }
    }
    
    error_log("Processed archaeologist - Name: $name, ORCID: $orcid, Email: $email", 3, OMEKA_PATH . '/logs/archaeologist-debug.log');
    
    return [
        'existing' => false,
        'name' => $name,
        'orcid' => $orcid,
        'email' => $email
    ];
}

/**
 * Generate excavation description from entity data
 */
private function generateExcavationDescription($excavationData, $entitiesData)
{
    $description = "Archaeological excavation";
    
    // Add location if available
    if (!empty($excavationData['location'])) {
        $description .= " at " . $excavationData['location'];
    }
    
    // Add entity counts
    $counts = [];
    if (!empty($entitiesData['contexts'])) {
        $counts[] = count($entitiesData['contexts']) . " context(s)";
    }
    if (!empty($entitiesData['svus'])) {
        $counts[] = count($entitiesData['svus']) . " stratigraphic unit(s)";
    }
    if (!empty($entitiesData['squares'])) {
        $counts[] = count($entitiesData['squares']) . " square(s)";
    }
    if (!empty($entitiesData['encounters'])) {
        $counts[] = count($entitiesData['encounters']) . " encounter event(s)";
    }
    
    if (!empty($counts)) {
        $description .= " containing " . implode(', ', $counts);
    }
    
    return $description;
}

/**
 * Process entity selection from form data, handling both existing and new entities
 * 
 * @param string|null $existingUri URI of existing entity (if selected)
 * @param array $newData Data for creating a new entity
 * @param string $entityType Type of entity (Context, SVU, EncounterEvent)
 * @return array|null Processed entity data or null if no valid data
 */
private function processEntitySelection($existingUri, array $newData, $entityType)
{
    if (!empty($existingUri)) {
        return ['uri' => $existingUri, 'isExisting' => true, 'type' => $entityType];
    }
    
    // For Context, always ensure we have at least a default one if none provided
    if ($entityType === 'Context' && empty($newData['id'])) {
        $newData['id'] = 'CTX-' . uniqid();
    }
    
    // Check if we have the minimum required data to create a new entity
    switch ($entityType) {
        case 'Context':
            // Already handled above
            break;
        case 'SVU':
            if (empty($newData['id'])) {
                return null;
            }
            break;
        case 'EncounterEvent':
            if (empty($newData['date'])) {
                return null;
            }
            break;
    }
    
    return [
        'data' => $newData, 
        'isExisting' => false, 
        'type' => $entityType
    ];
}

/**
 * Generate TTL for a context
 */
private function generateContextTtl($contextUri, $contextData, $svuUri = null)
{
    $ttl = "<$contextUri> a crmarchaeo:A1_Excavation_Processing_Unit;\n";
    $ttl .= "    dct:identifier \"" . $contextData['id'] . "\"^^xsd:string;\n";
    
    if (!empty($contextData['description'])) {
        $ttl .= "    dct:description \"" . $contextData['description'] . "\"^^xsd:string;\n";
    }
    
    if ($svuUri) {
        $ttl .= "    excav:hasSVU <$svuUri>;\n";
    }
    
    $ttl .= "    .\n\n";
    return $ttl;
}

/**
 * Generate TTL for a Stratigraphic Volume Unit (SVU)
 */
private function generateSvuTtl($svuUri, $svuData)
{
    $ttl = "<$svuUri> a crmarchaeo:A2_Stratigraphic_Volume_Unit;\n";
    $ttl .= "    dct:identifier \"" . $svuData['id'] . "\"^^xsd:string;\n";
    
    if (!empty($svuData['description'])) {
        $ttl .= "    dct:description \"" . $svuData['description'] . "\"^^xsd:string;\n";
    }
    
    // Add timeline if year data is provided
    if (!empty($svuData['lower_year']) || !empty($svuData['upper_year'])) {
        $timelineUri = $svuUri . "/timeline";
        $ttl .= "    excav:hasTimeLine <$timelineUri>;\n";
        $ttl .= "    .\n\n";
        
        // Add timeline
        $ttl .= "<$timelineUri> a time:TemporalEntity;\n";
        
        if (!empty($svuData['lower_year'])) {
            $lowerInstantUri = $timelineUri . "/beginning";
            $ttl .= "    time:hasBeginning <$lowerInstantUri>;\n";
        }
        
        if (!empty($svuData['upper_year'])) {
            $upperInstantUri = $timelineUri . "/end";
            $ttl .= "    time:hasEnd <$upperInstantUri>;\n";
        }
        
        $ttl .= "    .\n\n";
        
        // Add instants
        if (!empty($svuData['lower_year'])) {
            $ttl .= "<$lowerInstantUri> a time:Instant;\n";
            $ttl .= "    time:inXSDYear \"" . $svuData['lower_year'] . "\"^^xsd:gYear;\n";
            $ttl .= "    excav:bc " . ($svuData['lower_bc'] ? "true" : "false") . ";\n";
            $ttl .= "    .\n\n";
        }
        
        if (!empty($svuData['upper_year'])) {
            $ttl .= "<$upperInstantUri> a time:Instant;\n";
            $ttl .= "    time:inXSDYear \"" . $svuData['upper_year'] . "\"^^xsd:gYear;\n";
            $ttl .= "    excav:bc " . ($svuData['upper_bc'] ? "true" : "false") . ";\n";
            $ttl .= "    .\n\n";
        }
    } else {
        $ttl .= "    .\n\n";
    }
    
    return $ttl;
}

/**
 * Generate TTL for an encounter event
 */
private function generateEncounterTtl($encounterUri, $encounterData, $excavationUri, $contextUri = null, $svuUri = null)
{
    $ttl = "<$encounterUri> a crmsci:S19_Encounter_Event;\n";
    
    if (!empty($encounterData['date'])) {
        $ttl .= "    dct:date \"" . $encounterData['date'] . "\"^^xsd:date;\n";
    } else {
        $ttl .= "    dct:date \"" . date('Y-m-d') . "\"^^xsd:date;\n";
    }
    
    if (!empty($encounterData['depth'])) {
        $ttl .= "    dbo:depth \"" . $encounterData['depth'] . "\"^^xsd:decimal;\n";
    }
    
    $ttl .= "    excav:foundInAExcavation <$excavationUri>;\n";
    
    if ($contextUri) {
        $ttl .= "    excav:foundInAContext <$contextUri>;\n";
    }
    
    if ($svuUri) {
        $ttl .= "    excav:foundInSVU <$svuUri>;\n";
    }
    
    $ttl .= "    .\n\n";
    return $ttl;
}

/**
 * Generate TTL for a location
 */
private function generateLocationTtl($locationUri, $locationName)
{
    $ttl = "";
    $ttl .= "<$locationUri> a dbo:Place;\n";
    $ttl .= "    dbo:informationName \"$locationName\"^^xsd:string;\n";
    
    // Add placeholder district and parish if needed
    $districtUri = $locationUri . "/district";
    $parishUri = $locationUri . "/parish";
    
    $ttl .= "    dbo:district <$districtUri>;\n";
    $ttl .= "    dbo:parish <$parishUri>;\n";
    
    // Add placeholder coordinates
    $coordinatesUri = $locationUri . "/coordinates";
    $ttl .= "    excav:hasGPSCoordinates <$coordinatesUri>;\n";
    $ttl .= "    .\n\n";
    
    // Add district
    $ttl .= "<$districtUri> a dbo:District;\n";
    $ttl .= "    dbo:informationName \"Unknown District\"^^xsd:string;\n";
    $ttl .= "    .\n\n";
    
    // Add parish
    $ttl .= "<$parishUri> a dbo:Parish;\n";
    $ttl .= "    dbo:informationName \"Unknown Parish\"^^xsd:string;\n";
    $ttl .= "    .\n\n";
    
    // Add coordinates
    $ttl .= "<$coordinatesUri> a geo:SpatialThing;\n";
    $ttl .= "    geo:lat \"0.0\"^^xsd:decimal;\n";
    $ttl .= "    geo:long \"0.0\"^^xsd:decimal;\n";
    $ttl .= "    .\n\n";
    
    return $ttl;
}

/**
 * Get standard TTL prefixes for archaeological data
 */
private function getTtlPrefixes()
{
    return "@prefix ah: <http://www.purl.com/ah/ms/ahMS#>.\n" .
           "@prefix ah-vocab: <http://www.purl.com/ah/kos#>.\n" .
           "@prefix excav: <https://purl.org/ah/ms/excavationMS#>.\n" .
           "@prefix dct: <http://purl.org/dc/terms/>.\n" .
           "@prefix foaf: <http://xmlns.com/foaf/0.1/>.\n" .
           "@prefix rdfs: <http://www.w3.org/2000/01/rdf-schema#>.\n" .
           "@prefix schema: <http://schema.org/>.\n" .
           "@prefix skos: <http://www.w3.org/2004/02/skos/core#>.\n" .
           "@prefix xsd: <http://www.w3.org/2001/XMLSchema#>.\n" .
           "@prefix dbo: <http://dbpedia.org/ontology/>.\n" .
           "@prefix time: <http://www.w3.org/2006/time#>.\n" .
           "@prefix edm: <http://www.europeana.eu/schemas/edm#>.\n" .
           "@prefix dul: <http://www.ontologydesignpatterns.org/ont/dul/DUL.owl#>.\n" .
           "@prefix crm: <http://www.cidoc-crm.org/cidoc-crm/>.\n" .
           "@prefix crmsci: <https://cidoc-crm.org/extensions/crmsci/>.\n" .
           "@prefix crmarchaeo: <http://www.cidoc-crm.org/extensions/crmarchaeo/>.\n" .
           "@prefix geo: <http://www.w3.org/2003/01/geo/wgs84_pos#>.\n" .
           "@prefix sh: <http://www.w3.org/ns/shacl#>.\n\n";
}


private function getFormData(CollectingFormRepresentation $cForm): array
{
    $formData = [];
    $rawPost = $this->params()->fromPost();
    
    error_log('=== ENHANCED getFormData DEBUG ===', 3, OMEKA_PATH . '/logs/form-data-debug.log');
    
    foreach ($cForm->prompts() as $prompt) {
        $fieldName = 'prompt_' . $prompt->id();
        $promptText = $prompt->text();
        
        error_log("Processing prompt {$prompt->id()}: $promptText", 3, OMEKA_PATH . '/logs/form-data-debug.log');
        
        if (isset($rawPost[$fieldName])) {
            $value = $rawPost[$fieldName];
            error_log("  Found value: $value", 3, OMEKA_PATH . '/logs/form-data-debug.log');
            
            // Map based on prompt text (more reliable than type)
            if (stripos($promptText, 'site') !== false || stripos($promptText, 'name') !== false) {
                $formData['location'] = $value;
            } elseif (stripos($promptText, 'latitude') !== false) {
                $formData['latitude'] = $value;
            } elseif (stripos($promptText, 'longitude') !== false) {
                $formData['longitude'] = $value;
            } elseif (stripos($promptText, 'country') !== false) {
                $formData['country'] = $value;
            } elseif (stripos($promptText, 'parish') !== false) {
                $formData['parish'] = $value;
            } elseif (stripos($promptText, 'city') !== false) {
                $formData['city'] = $value;
            } elseif (stripos($promptText, 'acronym') !== false) {
                $formData['acronym'] = $value;
            }
            
            // Store all values for debugging
            $formData[$fieldName] = $value;
        } else {
            error_log("  No value found for $fieldName", 3, OMEKA_PATH . '/logs/form-data-debug.log');
        }
    }
    
    error_log('Final form data: ' . print_r($formData, true), 3, OMEKA_PATH . '/logs/form-data-debug.log');
    return $formData;
}

    public function submitAction()
{
    if (!$this->getRequest()->isPost()) {
        return $this->redirect()->toRoute('site', [], true);
    }

    $cForm = $this->api()
        ->read('collecting_forms', $this->params('form-id'))
        ->getContent();

    $form = $cForm->getForm();
    $form->setData($this->params()->fromPost());
    if ($form->isValid()) {
        [$itemData, $cItemData] = $this->getPromptData($cForm);

        // Temporarily give the user permission to create the Omeka and
        // Collecting items. This gives all roles all privileges to all
        // resources, which _should_ be safe since we're only passing
        // mediated data.
        $this->acl->allow();
        // Allow the can-assign-items privilege so the IndexController can
        // assign the current o:site to the item. This is needed becuase,
        // for some reason, the ACL does not ignore can-assign-items, even
        // with the above allow().
        $this->acl->allow(null, 'Omeka\Entity\Site', 'can-assign-items');

        // Create the Omeka item.
        $itemData['o:is_public'] = false;
        $itemData['o:item_set'] = [
            'o:id' => $cForm->itemSet() ? $cForm->itemSet()->id() : null,
        ];
        // Nothing needs to be done for the default site assignment. The
        // item adapter will automatically assign the proper sites.
        if (!$cForm->defaultSiteAssign()) {
            // Otherwise, assign the current site only.
            $itemData['o:site'] = [
                'o:id' => $this->currentSite()->id(),
            ];
        }
        $response = $this->api($form)
            ->create('items', $itemData, $this->params()->fromFiles());

        if ($response) {
            $item = $response->getContent();

            // Create the Collecting item.
            $cItemData['o:item'] = ['o:id' => $item->id()];
            $cItemData['o-module-collecting:form'] = ['o:id' => $cForm->id()];

            if ('user' === $cForm->anonType()) {
                // If the form has the "user" anonymity type, the item's
                // defualt anonymous flag is "false" becuase the related
                // prompt ("User Public") is naturally public.
                $cItemData['o-module-collecting:anon']
                    = $this->params()->fromPost(sprintf('anon_%s', $cForm->id()), false);
            }

            $response = $this->api($form)->create('collecting_items', $cItemData);

            if ($response) {
                $cItem = $response->getContent();

                // Send a submission email if the user opts-in and provides
                // an email address.
                $sendEmail = $this->params()->fromPost(sprintf('email_send_%s', $cForm->id()), false);
                if ($sendEmail && $cItem->userEmail()) {
                    $this->sendSubmissionEmail($cForm, $cItem);
                }
                // Send a notification email if configured to do so.
                $sendEmailNotify = $this->siteSettings()->get('collecting_email_notify');
                if ($sendEmailNotify) {
                    $this->sendNotificationEmail($cForm, $cItem);
                }

                return $this->redirect()->toRoute(null, ['action' => 'success'], true);
            }
        }

        // Out of an abundance of caution, revert back to default permissions.
        $this->acl->removeAllow();
    } else {
        $this->messenger()->addErrors($form->getMessages());
    }

    $view = new ViewModel;
    $view->setVariable('cForm', $cForm);
    return $view;
}

    public function successAction()
    {
        $cForm = $this->api()
            ->read('collecting_forms', $this->params('form-id'))
            ->getContent();
        $view = new ViewModel;
        $view->setVariable('cForm', $cForm);
        return $view;
    }

    public function tosAction()
    {
        $response = $this->getResponse();
        $response->getHeaders()->addHeaderLine('Content-Type', 'text/plain; charset=utf-8');
        $response->setContent($this->siteSettings()->get('collecting_tos'));
        return $response;
    }

    public function itemShowAction()
    {
        if ($this->siteSettings()->get('collecting_hide_collected_data')) {
            // Don't render the page if configured to hide it.
            return $this->redirect()->toRoute('site', [], true);
        }
        $site = $this->currentSite();
        $cItem = $this->api()
            ->read('collecting_items', $this->params('item-id'))->getContent();

        $view = new ViewModel;
        $view->setVariable('site', $site);
        $view->setVariable('cItem', $cItem);
        return $view;
    }

    /**
     * Get the prompt data needed to create the Omeka and Collecting items.
     *
     * @param CollectingFormRepresentation $cForm
     * @return array [itemData, cItemData]
     */
    protected function getPromptData(CollectingFormRepresentation $cForm)
    {
        // Derive the prompt IDs from the form names.
        $postedPrompts = [];
        foreach ($this->params()->fromPost() as $key => $value) {
            if (preg_match('/^prompt_(\d+)$/', $key, $matches)) {
                $postedPrompts[$matches[1]] = $value;
            }
        }

        $itemData = [];
        $cItemData = [];
        $inputData = [];

        // Note that we're iterating the known prompts, not the ones submitted
        // with the form. This way we accept only valid prompts.
        foreach ($cForm->prompts() as $prompt) {
            if (!isset($postedPrompts[$prompt->id()])) {
                // This prompt was not found in the POSTed data.
                continue;
            }
            switch ($prompt->type()) {
                case 'property':
                    switch ($prompt->inputType()) {
                        case 'url':
                            $itemData[$prompt->property()->term()][] = [
                                'type' => 'uri',
                                'property_id' => $prompt->property()->id(),
                                '@id' => $postedPrompts[$prompt->id()],
                            ];
                            break;
                        case 'item':
                            $itemData[$prompt->property()->term()][] = [
                                'type' => 'resource',
                                'property_id' => $prompt->property()->id(),
                                'value_resource_id' => $postedPrompts[$prompt->id()],
                            ];
                            break;
                        case 'numeric:timestamp':
                            $itemData[$prompt->property()->term()][] = [
                                'type' => 'numeric:timestamp',
                                'property_id' => $prompt->property()->id(),
                                '@value' => $postedPrompts[$prompt->id()],
                            ];
                            break;
                        case 'numeric:interval':
                            $itemData[$prompt->property()->term()][] = [
                                'type' => 'numeric:interval',
                                'property_id' => $prompt->property()->id(),
                                '@value' => $postedPrompts[$prompt->id()],
                            ];
                            break;
                        case 'numeric:duration':
                            $itemData[$prompt->property()->term()][] = [
                                'type' => 'numeric:duration',
                                'property_id' => $prompt->property()->id(),
                                '@value' => $postedPrompts[$prompt->id()],
                            ];
                            break;
                        case 'numeric:integer':
                            $itemData[$prompt->property()->term()][] = [
                                'type' => 'numeric:integer',
                                'property_id' => $prompt->property()->id(),
                                '@value' => $postedPrompts[$prompt->id()],
                            ];
                            break;
                        default:
                            $itemData[$prompt->property()->term()][] = [
                                'type' => 'literal',
                                'property_id' => $prompt->property()->id(),
                                '@value' => $postedPrompts[$prompt->id()],
                            ];
                    }
                    // Note that there's no break here. We need to save all
                    // property types as inputs so the relationship between the
                    // prompt and the user input isn't lost.
                case 'input':
                case 'user_private':
                case 'user_public':
                    // Do not save empty inputs.
                    if ('' !== trim($postedPrompts[$prompt->id()])) {
                        $inputData[] = [
                            'o-module-collecting:prompt' => $prompt->id(),
                            'o-module-collecting:text' => $postedPrompts[$prompt->id()],
                        ];
                    }
                    break;
                case 'user_name':
                    $cItemData['o-module-collecting:user_name'] = $postedPrompts[$prompt->id()];
                    break;
                case 'user_email':
                    $cItemData['o-module-collecting:user_email'] = $postedPrompts[$prompt->id()];
                    break;
                case 'media':
                    $itemData = $this->mediaTypeManager->get($prompt->mediaType())
                        ->itemData($itemData, $postedPrompts[$prompt->id()], $prompt);
                    break;
                default:
                    // Invalid prompt type. Do nothing.
                    break;
            }
        }

        $cItemData['o-module-collecting:input'] = $inputData;
        return [$itemData, $cItemData];
    }

    /**
     * Send a submission email.
     *
     * @param CollectingFormRepresentation $cForm
     * @param CollectingItemRepresentation $cItem
     */
    protected function sendSubmissionEmail($cForm, $cItem)
    {
        $i18nHelper = $this->viewHelpers()->get('i18n');
        $partialHelper = $this->viewHelpers()->get('partial');

        $messageContent = '';
        if ($cForm->emailText()) {
            $messageContent .= $cForm->emailText();
        }
        $messageContent .= sprintf(
            '<p>You submitted the following data on %s using the form “%s” on the site “%s”: %s</p>',
            $i18nHelper->dateFormat($cItem->item()->created(), 'long'),
            $cItem->form()->label(),
            $cItem->form()->site()->title(),
            $cItem->form()->site()->siteUrl(null, true)
        );
        $messageContent .= $partialHelper('common/collecting-item-inputs', ['cItem' => $cItem]);
        $messageContent .= '<p>(All data you submitted was saved, even if you do not see it here.)</p>';

        $messagePart = new MimePart($messageContent);
        $messagePart->setType('text/html');
        $messagePart->setCharset('UTF-8');

        $body = new MimeMessage;
        $body->addPart($messagePart);

        $options = [];
        $from = $this->siteSettings()->get('collecting_email');
        if ($from) {
            $options['from'] = $from;
        }
        $message = $this->mailer()->createMessage($options)
            ->addTo($cItem->userEmail(), $cItem->userName())
            ->setSubject($this->translate('Thank you for your submission'))
            ->setBody($body);
        $this->mailer()->send($message);
    }

    /**
     * Send a notification email.
     *
     * @param CollectingFormRepresentation $cForm
     * @param CollectingItemRepresentation $cItem
     */
    protected function sendNotificationEmail($cForm, $cItem)
    {
        $i18nHelper = $this->viewHelpers()->get('i18n');
        $partialHelper = $this->viewHelpers()->get('partial');
        $urlHelper = $this->viewHelpers()->get('url');

        $messageContent = '';
        if ($cForm->emailText()) {
            $messageContent .= $cForm->emailText();
        }
        $messageContent .= sprintf(
            '<p>A user submitted the following data on %s using the form “%s” on the site “%s”: %s</p>',
            $i18nHelper->dateFormat($cItem->item()->created(), 'long'),
            $cItem->form()->label(),
            $cItem->form()->site()->title(),
            $cItem->form()->site()->siteUrl(null, true)
        );
        $messageContent .= $partialHelper('common/collecting-item-inputs', ['cItem' => $cItem]);
        $messageContent .= sprintf(
            '<p><a href="%s">%s</a></p>',
            $urlHelper('admin/site/slug/collecting/item', ['item-id' => $cItem->id()], ['force_canonical' => true], true),
            'Go here to administer the submitted item.'
        );

        $messagePart = new MimePart($messageContent);
        $messagePart->setType('text/html');
        $messagePart->setCharset('UTF-8');

        $body = new MimeMessage;
        $body->addPart($messagePart);

        $options = [];
        $from = $this->siteSettings()->get('collecting_email');
        $to = $this->siteSettings()->get('collecting_email_notify');
        if ($from) {
            $options['from'] = $from;
        }
        $message = $this->mailer()->createMessage($options)
            ->addTo($to)
            ->setSubject($this->translate('Collecting submission notification'))
            ->setBody($body);
        $this->mailer()->send($message);
    }
}
