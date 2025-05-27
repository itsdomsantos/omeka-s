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
    private $graphdbEndpoint = "http://localhost:7200/repositories/megalod/rdf-graphs/service";
    private $graphdbQueryEndpoint = "http://localhost:7200/repositories/megalod";
    private $baseDataGraphUri = "https://purl.org/megalod/";
    private $router;
    private $httpClient;

    private $uploadedFiles = null;

    private $excavationData = null;

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
        // Add this temporary debugging function to IndexController.php to verify your prompt IDs:
    
    private function debugPromptMapping($formData) 
    {
        error_log('=== DEBUGGING PROMPT MAPPING ===', 3, OMEKA_PATH . '/logs/prompt-debug.log');
        
        // Log all prompt fields with their values
        // Check for problematic values
        foreach ($formData as $key => $value) {
            if (is_string($value) && (strpos($value, '"') !== false || strpos($value, '\n') !== false)) {
                error_log("POTENTIAL PROBLEM FIELD: $key = " . $value, 3, OMEKA_PATH . '/logs/debug-all-fields.log');
            }
        }
        
        error_log('=== END PROMPT MAPPING DEBUG ===', 3, OMEKA_PATH . '/logs/prompt-debug.log');
    }




/**
 * Unified TTL generation method that handles both simple and complex excavation data
 * Add this method to your AddTriplestore/IndexController.php
 */
private function prepareUnifiedTtlFromExcavationData(
    $excavationId, 
    $excavationData, 
    $entitiesData = null, 
    $archaeologistData = null,
    $legacyContextData = null,
    $legacySvuData = null, 
    $legacyEncounterData = null
) {
    // Debug log what we're working with
    error_log('=== TTL GENERATION DEBUG ===', 3, OMEKA_PATH . '/logs/ttl-debug.log');
    error_log('Excavation ID: ' . $excavationId, 3, OMEKA_PATH . '/logs/ttl-debug.log');
    error_log('Excavation Data: ' . print_r($excavationData, true), 3, OMEKA_PATH . '/logs/ttl-debug.log');
    error_log('Entities Data: ' . print_r($entitiesData, true), 3, OMEKA_PATH . '/logs/ttl-debug.log');
    error_log('Archaeologist Data: ' . print_r($archaeologistData, true), 3, OMEKA_PATH . '/logs/ttl-debug.log');
    
    // Ensure we have a valid excavation ID with proper prefix
    if (empty($excavationId) || $excavationId === "") {
        $excavationId = "EXC-" . uniqid();
    } else if (strpos($excavationId, 'EXC-') !== 0) {
        $excavationId = "EXC-" . $excavationId;
    }
    
    $baseUri = "https://purl.org/megalod/";
    $graphId = str_replace(['EXC-', ' '], ['', '_'], $excavationId);
    $excavationUri = "$baseUri$graphId";
    
    // Start TTL
    $ttl = $this->getTtlPrefixes();
    
    // Add excavation
    $ttl .= "<$excavationUri> a excav:Excavation;\n";
    $ttl .= "    dct:identifier \"$excavationId\"^^xsd:literal;\n";
    
    // Add basic excavation metadata from collecting form
    if (!empty($excavationData['location'])) {
        $ttl .= "    dct:title \"Excavation at " . $excavationData['location'] . "\"^^xsd:literal;\n";
    }
    
    if (!empty($excavationData['acronym'])) {
        $ttl .= "    skos:notation \"" . $excavationData['acronym'] . "\"^^xsd:literal;\n";
    }
    
    // Add location information - FIXED: Actually use the data
    if (!empty($excavationData['location']) || !empty($excavationData['latitude']) || !empty($excavationData['longitude'])) {
        $locationUri = "$excavationUri/location";
        $ttl .= "    dul:hasLocation <$locationUri>;\n";
    }
    
    // Add archaeologist - FIXED: Actually use the data
    if (!empty($archaeologistData)) {
        if ($archaeologistData['existing']) {
            $ttl .= "    excav:hasPersonInCharge <" . $archaeologistData['uri'] . ">;\n";
        } else if (!empty($archaeologistData['name'])) {
            $archaeologistUri = "$excavationUri/archaeologist";
            $ttl .= "    excav:hasPersonInCharge <$archaeologistUri>;\n";
        }
    }
    
    // ENHANCED ENTITIES PROCESSING (from complex form)
    if (!empty($entitiesData) && is_array($entitiesData)) {
        
        // Add contexts
        if (!empty($entitiesData['contexts'])) {
            foreach ($entitiesData['contexts'] as $context) {
                $contextUri = "$excavationUri/context/" . urlencode($context['context_id']);
                $ttl .= "    excav:hasContext <$contextUri>;\n";
            }
        }
        
        // Add squares
        if (!empty($entitiesData['squares'])) {
            foreach ($entitiesData['squares'] as $square) {
                $squareUri = "$excavationUri/square/" . urlencode($square['square_id']);
                $ttl .= "    excav:hasSquare <$squareUri>;\n";
            }
        }
    }
    
    $ttl .= "    .\n\n";
    
    // Add location details - FIXED: Corrected SHACL validation issues
    if (!empty($excavationData['location']) || !empty($excavationData['latitude']) || !empty($excavationData['longitude'])) {
        $locationUri = "$excavationUri/location";
        $ttl .= "<$locationUri> a excav:Location;\n";
        
        if (!empty($excavationData['location'])) {
            $ttl .= "    dbo:informationName \"" . $excavationData['location'] . "\"^^xsd:literal;\n";
        }
        
        // Add GPS coordinates - ACTUALLY USE THEM
        if (!empty($excavationData['latitude']) && !empty($excavationData['longitude'])) {
            $gpsUri = "$locationUri/gps";
            $ttl .= "    excav:hasGPSCoordinates <$gpsUri>;\n";
        }
        
        // FIXED: Add Parish as URI reference (not literal)
        if (!empty($excavationData['Parish'])) {
            $parishUri = "$locationUri/Parish";
            $ttl .= "    dbo:Parish <$parishUri>;\n";
        }
        
        // FIXED: Add Country as URI reference (not literal)
        if (!empty($excavationData['Country'])) {
            $countryUri = "http://dbpedia.org/resource/" . str_replace(' ', '_', $excavationData['Country']);
            $ttl .= "    dbo:Country <$countryUri>;\n";
        }
        
        // FIXED: Add city as URI reference (not literal)
        if (!empty($excavationData['city'])) {
            $cityUri = "$locationUri/city";
            $ttl .= "    dbo:city <$cityUri>;\n";
        }
        
        $ttl .= "    .\n\n";
        
        // Add GPS coordinates details - ACTUALLY CREATE THEM
        if (!empty($excavationData['latitude']) && !empty($excavationData['longitude'])) {
            $gpsUri = "$locationUri/gps";
            $ttl .= "<$gpsUri> a excav:GPSCoordinates;\n";
            $ttl .= "    geo:lat \"" . $excavationData['latitude'] . "\"^^xsd:decimal;\n";
            $ttl .= "    geo:long \"" . $excavationData['longitude'] . "\"^^xsd:decimal;\n";
            $ttl .= "    .\n\n";
        }
        
        // FIXED: Add Parish details with correct class
        if (!empty($excavationData['Parish'])) {
            $parishUri = "$locationUri/Parish";
            $ttl .= "<$parishUri> a dbo:Parish;\n";
            $ttl .= "    dbo:informationName \"" . $excavationData['Parish'] . "\"^^xsd:literal;\n";
            $ttl .= "    .\n\n";
        }
        
        // FIXED: Add city details
        if (!empty($excavationData['city'])) {
            $cityUri = "$locationUri/city";
            $ttl .= "<$cityUri> a dbo:City;\n";
            $ttl .= "    dbo:informationName \"" . $excavationData['city'] . "\"^^xsd:literal;\n";
            $ttl .= "    .\n\n";
        }
    }
    
    // Add archaeologist details - FIXED: Actually create the archaeologist
    if (!empty($archaeologistData) && !$archaeologistData['existing'] && !empty($archaeologistData['name'])) {
        $archaeologistUri = "$excavationUri/archaeologist";
        $ttl .= "<$archaeologistUri> a excav:Archaeologist;\n";
        $ttl .= "    foaf:name \"" . $archaeologistData['name'] . "\"^^xsd:literal;\n";
        
        if (!empty($archaeologistData['orcid'])) {
            // FIXED: Ensure ORCID is a proper URI
            $orcidUri = $archaeologistData['orcid'];
            if (strpos($orcidUri, 'http') !== 0) {
                // If it doesn't start with http, assume it's just the ID
                if (strpos($orcidUri, 'orcid.org') === false) {
                    $orcidUri = "https://orcid.org/" . $orcidUri;
                } else {
                    $orcidUri = "https://" . $orcidUri;
                }
            }
            $ttl .= "    foaf:account <$orcidUri>;\n";
        }
        
        if (!empty($archaeologistData['email'])) {
            // FIXED: Ensure email is proper mailto URI
            $emailUri = $archaeologistData['email'];
            if (strpos($emailUri, 'mailto:') !== 0) {
                $emailUri = "mailto:" . $emailUri;
            }
            $ttl .= "    foaf:mbox <$emailUri>;\n";
        }
        
        $ttl .= "    .\n\n";
    }
    
    // ENHANCED ENTITIES DECLARATIONS - Now actually create all the entities
    if (!empty($entitiesData) && is_array($entitiesData)) {
        
        // Add all contexts with full declarations
        if (!empty($entitiesData['contexts'])) {
            foreach ($entitiesData['contexts'] as $context) {
                $contextUri = "$excavationUri/context/" . urlencode($context['context_id']);
                $ttl .= "<$contextUri> a excav:Context;\n";
                $ttl .= "    dct:identifier \"" . $context['context_id'] . "\"^^xsd:literal;\n";
                
                if (!empty($context['context_description'])) {
                    $ttl .= "    dct:description \"" . $context['context_description'] . "\"^^xsd:literal;\n";
                }
                
                if (!empty($context['context_type'])) {
                    $ttl .= "    excav:contextType \"" . $context['context_type'] . "\"^^xsd:literal;\n";
                }
                
                // Link to SVUs based on relationships
                if (!empty($entitiesData['relationships'])) {
                    foreach ($entitiesData['relationships'] as $rel) {
                        $contextIndex = array_search($context, $entitiesData['contexts']);
                        if ($rel['context'] == $contextIndex && isset($entitiesData['svus'][$rel['svu']])) {
                            $svu = $entitiesData['svus'][$rel['svu']];
                            $svuUri = "$excavationUri/svu/" . urlencode($svu['svu_id']);
                            $ttl .= "    excav:hasSVU <$svuUri>;\n";
                        }
                    }
                }
                
                $ttl .= "    .\n\n";
            }
        }
        
        // Add all SVUs with full declarations
        if (!empty($entitiesData['svus'])) {
            foreach ($entitiesData['svus'] as $svu) {
                $svuUri = "$excavationUri/svu/" . urlencode($svu['svu_id']);
                $ttl .= "<$svuUri> a excav:StratigraphicVolumeUnit;\n";
                $ttl .= "    dct:identifier \"" . $svu['svu_id'] . "\"^^xsd:literal;\n";
                
                if (!empty($svu['svu_description'])) {
                    $ttl .= "    dct:description \"" . $svu['svu_description'] . "\"^^xsd:literal;\n";
                }
                
                // Add timeline if year data exists
                if (!empty($svu['svu_lower_year']) || !empty($svu['svu_upper_year'])) {
                    $timelineUri = "$svuUri/timeline";
                    $ttl .= "    excav:hasTimeline <$timelineUri>;\n";
                    $ttl .= "    .\n\n";
                    
                    // Add timeline details
                    $ttl .= "<$timelineUri> a excav:TimeLine;\n";
                    
                    if (!empty($svu['svu_lower_year'])) {
                        $beginUri = "$timelineUri/beginning";
                        $ttl .= "    time:hasBeginning <$beginUri>;\n";
                    }
                    
                    if (!empty($svu['svu_upper_year'])) {
                        $endUri = "$timelineUri/end";
                        $ttl .= "    time:hasEnd <$endUri>;\n";
                    }
                    
                    $ttl .= "    .\n\n";
                    
                    // Add instant details
                    if (!empty($svu['svu_lower_year'])) {
                        $beginUri = "$timelineUri/beginning";
                        $ttl .= "<$beginUri> a excav:Instant;\n";
                        $ttl .= "    time:inXSDgYear \"" . $svu['svu_lower_year'] . "\"^^xsd:gYear;\n";
                        $bcacValue = !empty($svu['svu_lower_bc']) ? "BC" : "AC";
                        $ttl .= "    excav:bcad <https://purl.org/megalod/kos/MegaLOD-BCAD/$bcacValue>;\n";
                        $ttl .= "    .\n\n";
                    }
                    
                    if (!empty($svu['svu_upper_year'])) {
                        $endUri = "$timelineUri/end";
                        $ttl .= "<$endUri> a excav:Instant;\n";
                        $ttl .= "    time:inXSDgYear \"" . $svu['svu_upper_year'] . "\"^^xsd:gYear;\n";
                        $bcacValue = !empty($svu['svu_upper_bc']) ? "BC" : "AC";
                        $ttl .= "    excav:bcad <https://purl.org/megalod/kos/MegaLOD-BCAD/$bcacValue>;\n";
                        $ttl .= "    .\n\n";
                    }
                } else {
                    $ttl .= "    .\n\n";
                }
            }
        }
        
        // Add all squares with full declarations
        if (!empty($entitiesData['squares'])) {
            foreach ($entitiesData['squares'] as $square) {
                $squareUri = "$excavationUri/square/" . urlencode($square['square_id']);
                $ttl .= "<$squareUri> a excav:Square;\n";
                $ttl .= "    dct:identifier \"" . $square['square_id'] . "\"^^xsd:literal;\n";
                
                if (!empty($square['square_east_west'])) {
                    $ttl .= "    geo:lat \"" . $square['square_east_west'] . "\"^^xsd:decimal;\n";
                }
                
                if (!empty($square['square_north_south'])) {
                    $ttl .= "    geo:long \"" . $square['square_north_south'] . "\"^^xsd:decimal;\n";
                }
                
                if (!empty($square['square_size'])) {
                    $ttl .= "    excav:squareSize \"" . $square['square_size'] . "\"^^xsd:decimal;\n";
                }
                
                $ttl .= "    .\n\n";
            }
        }
        
        // Add all encounter events with full declarations
        if (!empty($entitiesData['encounters'])) {
            foreach ($entitiesData['encounters'] as $encounter) {
                $encounterUri = "$excavationUri/encounter/" . uniqid();
                $ttl .= "<$encounterUri> a excav:EncounterEvent;\n";
                
                if (!empty($encounter['encounter_date'])) {
                    $ttl .= "    dct:date \"" . $encounter['encounter_date'] . "\"^^xsd:literal;\n";
                }
                
                if (!empty($encounter['encounter_depth'])) {
                    $ttl .= "    schema:depth \"" . $encounter['encounter_depth'] . "\"^^xsd:decimal;\n";
                }
                
                if (!empty($encounter['encounter_method'])) {
                    $ttl .= "    excav:excavationMethod \"" . $encounter['encounter_method'] . "\"^^xsd:literal;\n";
                }
                
                if (!empty($encounter['encounter_notes'])) {
                    $ttl .= "    dct:description \"" . $encounter['encounter_notes'] . "\"^^xsd:literal;\n";
                }
                
                $ttl .= "    excav:foundInExcavation <$excavationUri>;\n";
                $ttl .= "    .\n\n";
            }
        }
    }
    
    // Log the final TTL for debugging
    error_log('=== FINAL GENERATED TTL ===', 3, OMEKA_PATH . '/logs/ttl-debug.log');
    error_log($ttl, 3, OMEKA_PATH . '/logs/ttl-debug.log');
    
    return $ttl;
}



/**
 * Process archaeologist data from excavation form
 */
private function processExcavationArchaeologistData($formData)
{
    $archaeologistData = [
        'existing' => false,
        'name' => null,
        'orcid' => null,
        'email' => null
    ];
    
    // Check if existing archaeologist selected
    $existingId = $this->params()->fromPost('existing_archaeologist');
    if ($existingId) {
        try {
            $archaeologist = $this->api()->read('items', $existingId)->getContent();
            return [
                'existing' => true,
                'item_id' => $existingId,
                'uri' => $archaeologist->url()
            ];
        } catch (\Exception $e) {
            // Fall through to new archaeologist
        }
    }
    
    // Extract from enhanced form fields
    $archaeologistData['name'] = $this->params()->fromPost('new_archaeologist_name');
    $archaeologistData['orcid'] = $this->params()->fromPost('new_archaeologist_orcid');
    $archaeologistData['email'] = $this->params()->fromPost('new_archaeologist_email');
    
    // If enhanced form is empty, try to extract from collecting form
    if (empty($archaeologistData['name'])) {
        foreach ($formData as $key => $value) {
            if (strpos($key, 'prompt_') === 0 && !empty($value)) {
                // Based on your form values
                if ($value === 'Tiago') {
                    $archaeologistData['name'] = $value;
                } elseif (strpos($value, '@') !== false) {
                    $archaeologistData['email'] = $value;
                } elseif (strpos($value, 'orcid') !== false || strpos($value, 'feup') !== false) {
                    $archaeologistData['orcid'] = $value;
                }
            }
        }
    }
    
    error_log('Processed archaeologist data: ' . print_r($archaeologistData, true), 3, OMEKA_PATH . '/logs/excavation-form-debug.log');
    
    return $archaeologistData;
}


/**
 * Get an item's identifier value from its properties
 */
private function getItemIdentifier($item)
{
    if (!$item) {
        return null;
    }
    
    $values = $item->values();
    
    // First, try to get dcterms:identifier
    if (isset($values['dcterms:identifier']) && isset($values['dcterms:identifier'][0])) {
        $identifier = $values['dcterms:identifier'][0]->value();
        // If it's not just a number, use it
        if (!is_numeric($identifier)) {
            return $identifier;
        }
    }
    
    // Look for properties with "ID" in their label
    foreach ($values as $propertyValues) {
        if (!empty($propertyValues) && isset($propertyValues[0])) {
            $property = $propertyValues[0]->property();
            if ($property && strpos($property->label(), 'ID') !== false) {
                $identifier = $propertyValues[0]->value();
                // If it's not just a number, use it
                if (!is_numeric($identifier)) {
                    return $identifier;
                }
            }
        }
    }
    
    // Look at the item title for clues
    $title = $item->displayTitle();
    
    // For squares, look for grid references like A1, B2, etc.
    if (strpos($title, 'Square') !== false) {
        if (preg_match('/\b([A-Z]\d+)\b/i', $title, $matches)) {
            return $matches[1]; // Return something like "A1"
        }
        // Alternative patterns: "Square A-1", "Grid A1", etc.
        if (preg_match('/\b([A-Z]-?\d+)\b/i', $title, $matches)) {
            return str_replace('-', '', $matches[1]); // Convert "A-1" to "A1"
        }
    }
    
    // For contexts, look for patterns like CV-001, CTX-123, etc.
    if (strpos($title, 'Context') !== false) {
        if (preg_match('/\b(C[VTX]+-?\d+)\b/i', $title, $matches)) {
            return $matches[1];
        }
    }
    
    // For SVUs, look for patterns like SVU-001, SU-123, etc.
    if (strpos($title, 'Stratigraphic') !== false || strpos($title, 'SVU') !== false) {
        if (preg_match('/\b(SVU-?\d+|SU-?\d+)\b/i', $title, $matches)) {
            return $matches[1];
        }
    }
    
    // Extract any identifier-like pattern from the title as fallback
    if (preg_match('/\b([A-Z]+-?\d+|\d+)\b/i', $title, $matches)) {
        return $matches[1];
    }
    
    // If no meaningful identifier found, use the item ID as last resort
    return $item->id();
}
    
    /**
     * Update the getTtlPrefixes function with the new namespace prefixes
     */
    private function getTtlPrefixes()
    {
        return "@prefix rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#> .\n" .
               "@prefix rdfs: <http://www.w3.org/2000/01/rdf-schema#> .\n" .
               "@prefix sh: <http://www.w3.org/ns/shacl#> .\n" .
               "@prefix xsd: <http://www.w3.org/2001/XMLSchema#> .\n" .
               "@prefix skos: <http://www.w3.org/2004/02/skos/core#> .\n" .
               "@prefix dct: <http://purl.org/dc/terms/> .\n" .
               "@prefix foaf: <http://xmlns.com/foaf/0.1/> .\n" .
               "@prefix dbo: <http://dbpedia.org/ontology/> .\n" .
               "@prefix crm: <http://www.cidoc-crm.org/cidoc-crm/> .\n" .
               "@prefix crmsci: <http://cidoc-crm.org/extensions/crmsci/> .\n" .
               "@prefix crmarchaeo: <http://www.cidoc-crm.org/extensions/crmarchaeo/> .\n" .
               "@prefix edm: <http://www.europeana.eu/schemas/edm/> .\n" .
               "@prefix geo: <http://www.w3.org/2003/01/geo/wgs84_pos#> .\n" .
               "@prefix time: <http://www.w3.org/2006/time#> .\n" .
               "@prefix schema: <http://schema.org/> .\n" .
               "@prefix ah: <https://purl.org/megalod/ms/ah/> .\n" .
               "@prefix excav: <https://purl.org/megalod/ms/excavation/> .\n" .
               "@prefix dul: <http://www.ontologydesignpatterns.org/ont/dul/DUL.owl#> .\n\n";
    }
    
    /**
     * Helper function to sanitize values for use in URIs
     * Removes spaces, parentheses, and converts to lowercase
     */
    private function sanitizeForUri($value) {
        // Extract text before parentheses if present
        if (preg_match('/^([^(]+)/', $value, $matches)) {
            $value = trim($matches[1]);
        }
        
        // Convert to lowercase and remove spaces and special characters
        $value = strtolower($value);
        $value = preg_replace('/[\s()]+/', '', $value);
        
        return $value;
    }

    

    public function uploadAction()
    {
        // Get all POST data
        $postData = $this->params()->fromPost();
        
        // Check if this is a continuous arrowhead upload
        $uploadType = $this->params()->fromQuery('upload_type') ?: $this->params()->fromPost('upload_type');
        $itemSetId = $this->params()->fromQuery('item_set_id') ?: $this->params()->fromPost('item_set_id');
        $mode = $this->params()->fromQuery('mode', $this->params()->fromPost('mode', 'upload'));
    
        
        // Process arrowhead file upload
        if ($mode == 'file' && $uploadType == 'arrowhead' && $itemSetId) {
            $file = $this->params()->fromFiles('file');
            if ($file && !empty($file['tmp_name'])) {
                // Process the uploaded file
                error_log('File upload detected: ' . $file['name'], 3, OMEKA_PATH . '/logs/aaaaaaaaaaa.log');
                $result = $this->processFileUpload($this->getRequest(), $uploadType, $itemSetId);
                error_log('Arrowhead upload result: ' . $result, 3, OMEKA_PATH . '/logs/aaaaaaaaaaa.log');
                // Check if excavation ID is available for a more specific message
                $excavationId = $this->getExcavationIdentifierFromItemSet($itemSetId);
                error_log('Excavation ID: ' . $excavationId, 3, OMEKA_PATH . '/logs/aaaaaaaaaaa.log');
                if ($excavationId && strpos($result, 'successfully') !== false) {
                    $result = "Arrowhead was successfully added to excavation $excavationId (Item Set #$itemSetId). You can upload another or click Exit when done.";
                }
                
                // Redirect back to the same page to enable continuous uploads
                $url = $this->url()->fromRoute('site/add-triplestore/upload', [
                    'site-slug' => $this->currentSite()->slug(),
                ], [
                    'query' => [
                        'upload_type' => 'arrowhead',
                        'item_set_id' => $itemSetId,
                        'mode' => 'file',
                        'result' => $result
                    ]
                ]);
                return $this->redirect()->toUrl($url);
            }
            
            // Show arrowhead upload form
            $view = new ViewModel([
                'itemSetId' => $itemSetId,
                'uploadType' => $uploadType,
                'result' => $this->params()->fromQuery('result')
            ]);
            $view->setTemplate('add-triplestore/site/index/upload-arrowhead');
            return $view;
        }

        // Revised fix for the arrowhead form processing
        if ($mode == 'form' && $uploadType == 'arrowhead') {
            // Check if we have POST data (a form submission)
            $formData = $this->params()->fromPost();
            
            // Only process if there's actual form data and no success flag in the query
            $success = $this->params()->fromQuery('success', false);
            
            if (!empty($formData) && empty($success)) {
                // This is a real form submission, process it
                $ttlData = $this->processArrowheadFormData($formData, $itemSetId);
                error_log('Creating arrowhead with id: ' . $formData['arrowhead_identifier'], 3, OMEKA_PATH . '/logs/new-aux.log');
                // log ttl data for debugging
                error_log('TTL data: ' . $ttlData, 3, OMEKA_PATH . '/logs/new-aux-ttl.log');
                // Upload TTL data to triplestore
                $result = $this->uploadTtlData($ttlData, $itemSetId) ?? 'Unknown error occurred during upload';
                error_log('Arrowhead upload result: ' . $result, 3, OMEKA_PATH . '/logs/new-aux.log');
                
                // Redirect to success page
                $url = $this->url()->fromRoute('site/add-triplestore/upload', [
                    'site-slug' => $this->currentSite()->slug(),
                ], [
                    'query' => [
                        'upload_type' => 'arrowhead',
                        'item_set_id' => $itemSetId,
                        'mode' => 'form',
                        'result' => $result,
                        'success' => '1'
                    ]
                ]);
                
                error_log('Redirecting to URL: ' . $url, 3, OMEKA_PATH . '/logs/new-aux.log');
                return $this->redirect()->toUrl($url);
            } else {
                // Either this is just a page view, or we're viewing after a success
                // Simply render the template with proper variables
                $view = new ViewModel([
                    'itemSetId' => $itemSetId,
                    'uploadType' => $uploadType,
                    'result' => $this->params()->fromQuery('result', ''),
                    'success' => $success
                ]);
                $view->setTemplate('add-triplestore/site/index/upload-arrowhead');
                return $view;
            }
        }

        
// Replace your excavation form processing section in AddTriplestore/IndexController.php with this:

// Process the excavation form submission
if ($uploadType == 'excavation' && !isset($_FILES['file'])) {
  // TO DO
}

        
        // For direct file uploads - handle normally
        else if (isset($_FILES['file']) && !empty($_FILES['file']['tmp_name'])) {
            // Log the upload type for debugging
            error_log('File upload detected: ' . $uploadType, 3, OMEKA_PATH . '/logs/file-upload.log');
            
            $result = $this->processFileUpload($this->getRequest(), $uploadType, $itemSetId);
            
            // If this is an excavation file upload, create an item set if needed and redirect to arrowhead upload
            if ($uploadType == 'excavation') {
                error_log('Processing excavation file upload', 3, OMEKA_PATH . '/logs/a.log');
                // Extract excavation identifier from the upload result
                error_log('Upload result: ' . $result, 3, OMEKA_PATH . '/logs/a.log');
                preg_match('/Excavation ([A-Za-z0-9-]+)/', $result, $matches);                $excavationIdentifier = isset($matches[1]) ? $matches[1] : null;
                error_log('Excavation identifier: ' . $excavationIdentifier, 3, OMEKA_PATH . '/logs/a.log');
                if ($excavationIdentifier) {
                    // Get the item set ID either from the upload result or from the mapping
                    if (strpos($result, 'Item Set #') !== false) {
                        preg_match('/Item Set #(\d+)/', $result, $matches);
                        $itemSetId = isset($matches[1]) ? $matches[1] : null;
                    }
                    
                    if ($itemSetId) {
                        // Redirect to the arrowhead upload form with the excavation context
                        return $this->redirect()->toUrl($this->url()->fromRoute('site/add-triplestore/upload', [
                            'site-slug' => $this->currentSite()->slug(),
                        ], [
                            'query' => [
                                'upload_type' => 'arrowhead',
                                'item_set_id' => $itemSetId,
                                'mode' => 'file',
                                'result' => $result
                            ]
                        ]));
                    }
                }
            }
            // Check if this is supposed to be a continuous arrowhead upload (fallback)
            if ($mode == 'file' && $uploadType == 'arrowhead' && $itemSetId) {
                // Check if excavation ID is available for a more specific message
                error_log('Item Set ID: ' . $itemSetId, 3, OMEKA_PATH . '/logs/ab.log');
                $excavationId = $this->getExcavationIdentifierFromItemSet($itemSetId);
                error_log('Excavation ID: ' . $excavationId, 3, OMEKA_PATH . '/logs/ab.log');
                if ($excavationId && strpos($result, 'successfully') !== false) {
                    $result = "Arrowhead was successfully added to excavation $excavationId (Item Set #$itemSetId). You can upload another or click Exit when done.";
                }
                
                // Redirect back to the arrowhead upload page
                return $this->redirect()->toUrl($this->url()->fromRoute('site/add-triplestore/upload', [
                    'site-slug' => $this->currentSite()->slug(),
                ], [
                    'query' => [
                        'upload_type' => 'arrowhead',
                        'item_set_id' => $itemSetId,
                        'mode' => 'file',
                        'result' => $result
                    ]
                ]));
            }
            
            // For excavation file uploads, make sure we redirect to a page where arrowheads can be added
            if ($uploadType == 'excavation' && strpos($result, 'successfully') !== false) {
                // Try to extract item set ID from the result
                preg_match('/Item Set #(\d+)/', $result, $matches);
                $newItemSetId = isset($matches[1]) ? $matches[1] : null;
                
                if ($newItemSetId) {
                    return $this->redirect()->toUrl($this->url()->fromRoute('site/add-triplestore/upload', [
                        'site-slug' => $this->currentSite()->slug(),
                    ], [
                        'query' => [
                            'upload_type' => 'arrowhead',
                            'item_set_id' => $newItemSetId,
                            'mode' => 'file',
                            'result' => $result
                        ]
                    ]));
                }
            }
            
            // Normal redirect to the index page with the result if we couldn't determine a better redirect
            return $this->redirect()->toUrl($this->url()->fromRoute('site', [
                'site-slug' => $this->currentSite()->slug()
            ], [
                'query' => [
                    'result' => $result,
                    'item_set_id' => $itemSetId
                ]
            ]));
        }
        
        // Default response if no specific upload type was recognized
        return $this->redirect()->toUrl($this->url()->fromRoute('site', ['site-slug' => $this->currentSite()->slug()]));
    }




/**
 * Process arrowhead form data with enhanced resource linking
 */
private function processArrowheadFormData($formData, $itemSetId)
{
    // Debug log the incoming form data
    error_log('Form data received in processArrowheadFormData: ' . print_r($formData, true), 3, OMEKA_PATH . '/logs/form-debug.log');
    
    // Generate a base URI for resources
    $baseUri = "https://purl.org/megalod/$itemSetId";
    
    // Generate a unique ID for the arrowhead if not provided
    $arrowheadId = !empty($formData['arrowhead_identifier']) 
        ? $formData['arrowhead_identifier'] 
        : 'AH-' . uniqid();
    
    // Create resource URIs
    $arrowheadUri = "$baseUri/item/$arrowheadId";
    $morphologyUri = "$baseUri/morphology/$arrowheadId";
    $chippingUri = "$baseUri/chipping/$arrowheadId";
    $excavationUri = "https://purl.org/megalod/$itemSetId";
    $encounterUri = "$baseUri/encounter/$arrowheadId";
    
    // Build TTL data
    $ttl = $this->getTtlPrefixes();
    
    // Add excavation reference
    $ttl .= "<$excavationUri> a excav:Excavation;\n";
    $ttl .= "    dct:identifier \"EXC-$itemSetId\"^^xsd:literal;\n";
    $ttl .= "    .\n\n";
    
    // Add arrowhead - start the main resource
    $ttl .= "<$arrowheadUri> a ah:Arrowhead, excav:Item;\n";
    $ttl .= "    dct:identifier \"$arrowheadId\"^^xsd:literal;\n";
    $ttl .= "    excav:foundInExcavation <$excavationUri>;\n";
    
    // ADD ENHANCED RESOURCE LINKING SECTION
    // Check for selected square, context, and SVU from the form
    if (!empty($formData['selected_square'])) {
        $squareItemId = $formData['selected_square'];
        $squareUri = "$baseUri/square/item-$squareItemId";
        $ttl .= "    excav:foundInSquare <$squareUri>;\n";
        
        // Initialize and add square declaration
        $additionalDeclarations = "";
        $additionalDeclarations .= "<$squareUri> a excav:Square;\n";
        $additionalDeclarations .= "    dct:identifier \"Square-$squareItemId\"^^xsd:literal;\n";
        $additionalDeclarations .= "    .\n\n";
        
        error_log("Added square link: $squareItemId", 3, OMEKA_PATH . '/logs/form-debug.log');
    }
    
    if (!empty($formData['selected_context'])) {
        $contextItemId = $formData['selected_context'];
        $contextUri = "$baseUri/context/item-$contextItemId";
        $ttl .= "    excav:foundInContext <$contextUri>;\n";
        
        // Add context declaration
        $additionalDeclarations .= "<$contextUri> a excav:Context;\n";
        $additionalDeclarations .= "    dct:identifier \"Context-$contextItemId\"^^xsd:literal;\n";
        $additionalDeclarations .= "    .\n\n";
        
        error_log("Added context link: $contextItemId", 3, OMEKA_PATH . '/logs/form-debug.log');
    }

    if (!empty($formData['gps_latitude'])) {
        $ttl .= "    geo:lat \"{$formData['gps_latitude']}\"^^xsd:decimal;\n";
    }
    
    if (!empty($formData['gps_longitude'])) {
        $ttl .= "    geo:long \"{$formData['gps_longitude']}\"^^xsd:decimal;\n";
    }
    
    // Alternative: if you want to use a single GPS coordinate field
    if (!empty($formData['gps_coordinates'])) {
        // Parse "lat, long" format
        $coords = explode(',', $formData['gps_coordinates']);
        if (count($coords) == 2) {
            $lat = trim($coords[0]);
            $long = trim($coords[1]);
            $ttl .= "    geo:lat \"$lat\"^^xsd:decimal;\n";
            $ttl .= "    geo:long \"$long\"^^xsd:decimal;\n";
        }
    }
    
    if (!empty($formData['selected_svu'])) {
        $svuItemId = $formData['selected_svu'];
        $svuUri = "$baseUri/svu/item-$svuItemId";
        $ttl .= "    excav:foundInSVU <$svuUri>;\n";
        
        // Add SVU declaration
        $additionalDeclarations .= "<$svuUri> a excav:StratigraphicVolumeUnit;\n";
        $additionalDeclarations .= "    dct:identifier \"SVU-$svuItemId\"^^xsd:literal;\n";
        $additionalDeclarations .= "    .\n\n";
        
        error_log("Added SVU link: $svuItemId", 3, OMEKA_PATH . '/logs/form-debug.log');
    }
    
    // Continue with existing form processing...
    
    // Add annotation if provided
    if (!empty($formData['arrowhead_annotation'])) {
        $ttl .= "    dbo:Annotation \"" . $formData['arrowhead_annotation'] . "\"^^xsd:literal;\n";
    }
    
    // Add condition state
    if (!empty($formData['condition_state'])) {
        $value = (stripos($formData['condition_state'], 'true') !== false) ? "true" : "false";
        $ttl .= "    crm:E3_Condition_State \"$value\"^^xsd:boolean;\n";
    }
    
    // Add type (elongate/short)
    if (!empty($formData['arrowhead_type'])) {
        $value = (stripos($formData['arrowhead_type'], 'true') !== false) ? "true" : "false";
        $ttl .= "    crm:E55_Type \"$value\"^^xsd:boolean;\n";
    }
    
    // Add elongation index
    if (!empty($formData['elongation_index'])) {
        $ttl .= "    excav:elongationIndex <https://purl.org/megalod/kos/MegaLOD-IndexElongation/" . $formData['elongation_index'] . ">;\n";
    }
    
    // Add material
    if (!empty($formData['arrowhead_material'])) {
        $ttl .= "    crm:E57_Material <" . $formData['arrowhead_material'] . ">;\n";
    }
    
    if (!empty($formData['arrowhead_shape'])) {
        $shapeMapping = [
            'triangle' => 'triangle',
            'lozenge-shaped' => 'losangular',  // CHANGED: Fixed vocabulary term
            'losangular' => 'losangular',      // Support both forms
            'stemmed' => 'stemmed'
        ];
        
        $shapeSafe = isset($shapeMapping[$formData['arrowhead_shape']]) 
            ? $shapeMapping[$formData['arrowhead_shape']] 
            : strtolower(str_replace('-', '-', $formData['arrowhead_shape']));
            
        $ttl .= "    ah:shape <https://purl.org/megalod/kos/ah-shape/$shapeSafe>;\n";
    }
    
    // Add variant if selected
    if (!empty($formData['arrowhead_variant'])) {
        $variantSafe = strtolower($formData['arrowhead_variant']);
        $ttl .= "    ah:variant <https://purl.org/megalod/kos/ah-variant/$variantSafe>;\n";
    }

    // Initialize measurement blocks collection
    $measurementBlocks = "";
$processedMeasurements = []; // Track what we've processed

// Process basic measurements
$measurements = [
    'height' => 'height',
    'width' => 'width', 
    'weight' => 'weight', 
];

foreach ($measurements as $measurement => $property) {
    $valueKey = $measurement;
    $unitKey = $measurement . '_unit';
    
    if (!empty($formData[$valueKey])) {
        $measurementUri = "$baseUri/typometry/$arrowheadId-$measurement";
        
        // Debug log
        error_log("Processing measurement: $measurement with unit: " . ($formData[$unitKey] ?? 'none'), 3, OMEKA_PATH . '/logs/measurement-debug.log');
        
        // Add property to main resource
        if ($measurement === 'weight') {
            $ttl .= "    schema:weight <$measurementUri>;\n";
            
            // FIXED WEIGHT HANDLING
            $measurementBlocks .= "<$measurementUri> a excav:Weight;\n";
            $measurementBlocks .= "    schema:value \"" . $formData[$valueKey] . "\"^^xsd:decimal;\n";
            
            // Only add unit if it exists and we haven't processed this measurement yet
            if (!empty($formData[$unitKey]) && !isset($processedMeasurements[$measurementUri])) {
                $weightUnit = $formData[$unitKey];
                $measurementBlocks .= "    schema:UnitCode <$weightUnit>;\n";
                $processedMeasurements[$measurementUri] = true; // Mark as processed
                error_log("Added weight unit: $weightUnit for URI: $measurementUri", 3, OMEKA_PATH . '/logs/measurement-debug.log');
            }
            $measurementBlocks .= "    .\n\n";
        } else {
            // REGULAR HANDLING FOR OTHER MEASUREMENTS
            $ttl .= "    schema:$property <$measurementUri>;\n";
            
            $measurementBlocks .= "<$measurementUri> a excav:TypometryValue;\n";
            $measurementBlocks .= "    schema:value \"" . $formData[$valueKey] . "\"^^xsd:decimal;\n";
            
            // Only add unit if it exists and we haven't processed this measurement yet
            if (!empty($formData[$unitKey]) && !isset($processedMeasurements[$measurementUri])) {
                $measurementBlocks .= "    schema:UnitCode <" . $formData[$unitKey] . ">;\n";
                $processedMeasurements[$measurementUri] = true; // Mark as processed
                error_log("Added unit: {$formData[$unitKey]} for URI: $measurementUri", 3, OMEKA_PATH . '/logs/measurement-debug.log');
            }
            $measurementBlocks .= "    .\n\n";
        }
    }
}

// IMPORTANT: Make sure you're not processing weight again elsewhere in your code
// Check for any other places where you might be adding schema:UnitCode to the weight resource

// Add thickness (separate because it uses schema:depth)
if (!empty($formData['thickness'])) {
    $thicknessUri = "$baseUri/typometry/$arrowheadId-thickness";
    
    // Make sure this URI is different from weight URI
    if (!isset($processedMeasurements[$thicknessUri])) {
        $ttl .= "    schema:depth <$thicknessUri>;\n";
        
        $measurementBlocks .= "<$thicknessUri> a excav:TypometryValue;\n";
        $measurementBlocks .= "    schema:value \"" . $formData['thickness'] . "\"^^xsd:decimal;\n";
        if (!empty($formData['thickness_unit'])) {
            $measurementBlocks .= "    schema:UnitCode <" . $formData['thickness_unit'] . ">;\n";
        }
        $measurementBlocks .= "    .\n\n";
        $processedMeasurements[$thicknessUri] = true;
    }
}

// Body length and base length processing...
if (!empty($formData['body_length'])) {
    $bodyLengthUri = "$baseUri/typometry/$arrowheadId-hasBodyLength";
    
    if (!isset($processedMeasurements[$bodyLengthUri])) {
        $ttl .= "    ah:hasBodyLength <$bodyLengthUri>;\n";
        
        $measurementBlocks .= "<$bodyLengthUri> a excav:TypometryValue;\n";
        $measurementBlocks .= "    schema:value \"" . $formData['body_length'] . "\"^^xsd:decimal;\n";
        if (!empty($formData['body_length_unit'])) {
            $measurementBlocks .= "    schema:UnitCode <" . $formData['body_length_unit'] . ">;\n";
        }
        $measurementBlocks .= "    .\n\n";
        $processedMeasurements[$bodyLengthUri] = true;
    }
}

if (!empty($formData['base_length'])) {
    $baseLengthUri = "$baseUri/typometry/$arrowheadId-hasBaseLength";
    
    if (!isset($processedMeasurements[$baseLengthUri])) {
        $ttl .= "    ah:hasBaseLength <$baseLengthUri>;\n";
        
        $measurementBlocks .= "<$baseLengthUri> a excav:TypometryValue;\n";
        $measurementBlocks .= "    schema:value \"" . $formData['base_length'] . "\"^^xsd:decimal;\n";
        if (!empty($formData['base_length_unit'])) {
            $measurementBlocks .= "    schema:UnitCode <" . $formData['base_length_unit'] . ">;\n";
        }
        $measurementBlocks .= "    .\n\n";
        $processedMeasurements[$baseLengthUri] = true;
    }
}

// Debug: Log all processed measurements
error_log('All processed measurements: ' . print_r($processedMeasurements, true), 3, OMEKA_PATH . '/logs/measurement-debug.log');
    
    // Add chipping information
    $hasChippingData = !empty($formData['chipping_mode']) || 
                       !empty($formData['chipping_amplitude']) || 
                       !empty($formData['chipping_direction']);
    
    if ($hasChippingData) {
        $ttl .= "    ah:hasChipping <$chippingUri>;\n";
    }
    
    // Add morphology reference
    $ttl .= "    ah:hasMorphology <$morphologyUri>;\n";

    if (!empty($formData['x_coordinate']) && !empty($formData['y_coordinate'])) {
        $coordinatesUri = "$baseUri/coordinatesInSquare/" . substr($arrowheadId, 3); // Remove 'AH-' prefix
        $ttl .= "    excav:hasCoordinatesInSquare <$coordinatesUri>;\n";
    
        // Store coordinate data for later processing
        $coordinatesData = [
            'uri' => $coordinatesUri,
            'x' => $formData['x_coordinate'],
            'x_unit' => !empty($formData['x_coordinate_unit']) ? $formData['x_coordinate_unit'] : 'CMT',
            'y' => $formData['y_coordinate'],
            'y_unit' => !empty($formData['y_coordinate_unit']) ? $formData['y_coordinate_unit'] : 'CMT',
            'z' => !empty($formData['z_coordinate']) ? $formData['z_coordinate'] : null,
            'z_unit' => !empty($formData['z_coordinate_unit']) ? $formData['z_coordinate_unit'] : 'CMT'
        ];
    }
    
    
    // Add images/web resources
    if (!empty($formData['images'])) {
        $images = $formData['images'];
        error_log('Images data: ' . print_r($images, true), 3, OMEKA_PATH . '/logs/images-debug.log');
        if (is_array($images)) {
            foreach ($images as $image) {
                if (!empty($image)) {
                    $ttl .= "    edm:Webresource <$image>;\n";
                }
            }
        } else if (!empty($images)) {
            $ttl .= "    edm:Webresource <$images>;\n";
        }
    }
    
    // Close the main arrowhead resource
    $ttl .= "    .\n\n";

    if (!empty($coordinatesData)) {
        $ttl .= "<{$coordinatesData['uri']}> a excav:Coordinates;\n";
        
        // Add coordinates as schema:value properties in order: X, Y, Z
        $ttl .= "    schema:value \"{$coordinatesData['x']} {$coordinatesData['x_unit']}\"^^xsd:literal;\n";
        $ttl .= "    schema:value \"{$coordinatesData['y']} {$coordinatesData['y_unit']}\"^^xsd:literal;\n";
        
        if ($coordinatesData['z']) {
            $ttl .= "    schema:value \"{$coordinatesData['z']} {$coordinatesData['z_unit']}\"^^xsd:literal;\n";
        }
        
        $ttl .= "    .\n\n";
        
        error_log('Generated coordinates TTL block: ' . $ttl, 3, OMEKA_PATH . '/logs/coordinates-ttl.log');
    }
    
    // Add morphology
    $ttl .= "<$morphologyUri> a ah:Morphology;\n";
    
// Add point definition  
if (!empty($formData['point_definition'])) {
    $value = (stripos($formData['point_definition'], 'true') !== false) ? "true" : "false";
    $ttl .= "    ah:point \"$value\"^^xsd:boolean;\n";
}

    
    // Add body symmetry
    if (!empty($formData['body_symmetry'])) {
        $value = (stripos($formData['body_symmetry'], 'true') !== false) ? "true" : "false";
        $ttl .= "    ah:body \"$value\"^^xsd:boolean;\n";
    }
    
    // Add base if selected
    if (!empty($formData['arrowhead_base'])) {
        $baseSafe = strtolower($formData['arrowhead_base']);
        $ttl .= "    ah:base <https://purl.org/megalod/kos/ah-base/$baseSafe>;\n";
    }
    
    $ttl .= "    .\n\n";
    
    // Add chipping details if necessary
    if ($hasChippingData) {
        $ttl .= "<$chippingUri> a ah:Chipping;\n";
        
        // Add chipping mode
        if (!empty($formData['chipping_mode'])) {
            $modeSafe = strtolower(str_replace('-', '-', $formData['chipping_mode']));
            $ttl .= "    ah:chippingMode <https://purl.org/megalod/kos/ah-chippingMode/$modeSafe>;\n";
        }
        
        // Add chipping amplitude
        if (!empty($formData['chipping_amplitude'])) {
            $value = (stripos($formData['chipping_amplitude'], 'true') !== false) ? "true" : "false";
            $ttl .= "    ah:chippingAmplitude \"$value\"^^xsd:boolean;\n";
        }
        
        // Add chipping direction
        if (!empty($formData['chipping_direction'])) {
            $directionSafe = strtolower($formData['chipping_direction']);
            $ttl .= "    ah:chippingDirection <https://purl.org/megalod/kos/ah-chippingDirection/$directionSafe>;\n";
        }
        
        // Add chipping orientation
        if (!empty($formData['chipping_orientation'])) {
            $value = (stripos($formData['chipping_orientation'], 'true') !== false) ? "true" : "false";
            $ttl .= "    ah:chippingOrientation \"$value\"^^xsd:boolean;\n";
        }
        
        // Add chipping delineation
        if (!empty($formData['chipping_delineation'])) {
            $delineationSafe = strtolower($formData['chipping_delineation']);
            $ttl .= "    ah:chippingDelineation <https://purl.org/megalod/kos/ah-chippingDelineation/$delineationSafe>;\n";
        }
        
        // Add lateral chipping locations
        for ($i = 1; $i <= 3; $i++) {
            $lateralKey = "chipping_location_lateral_$i";
            if (!empty($formData[$lateralKey])) {
                $locationSafe = strtolower($formData[$lateralKey]);
                $ttl .= "    ah:chippingLocationSide <https://purl.org/megalod/kos/ah-chippingLocation/$locationSafe>;\n";
            }
        }
        
        // Add transversal chipping locations
        for ($i = 1; $i <= 3; $i++) {
            $transversalKey = "chipping_location_transversal_$i";
            if (!empty($formData[$transversalKey])) {
                $locationSafe = strtolower($formData[$transversalKey]);
                $ttl .= "    ah:chippingLocationTransversal <https://purl.org/megalod/kos/ah-chippingLocation/$locationSafe>;\n";
            }
        }
        
        // Add chipping shape
        if (!empty($formData['chipping_shape'])) {
            $shapeSafe = strtolower($formData['chipping_shape']);
            $ttl .= "    ah:chippingShape <https://purl.org/megalod/kos/ah-chippingShape/$shapeSafe>;\n";
        }
        
        $ttl .= "    .\n\n";
    }
    
    // Add encounter event
    $ttl .= "<$encounterUri> a excav:EncounterEvent;\n";
    $ttl .= "    dct:date \"" . date('Y-m-d') . "\"^^xsd:literal;\n";
    $ttl .= "    crmsci:O19_encountered_object <$arrowheadUri>;\n";
    $ttl .= "    excav:foundInExcavation <$excavationUri>;\n";
    
    // Add resource links to encounter event as well
    if (!empty($formData['selected_context'])) {
        $contextItemId = $formData['selected_context'];
        $contextUri = "$baseUri/context/item-$contextItemId";
        $ttl .= "    excav:foundInContext <$contextUri>;\n";
    }
    
    if (!empty($formData['selected_svu'])) {
        $svuItemId = $formData['selected_svu'];
        $svuUri = "$baseUri/svu/item-$svuItemId";
        $ttl .= "    excav:foundInSVU <$svuUri>;\n";
    }
    
    $ttl .= "    .\n\n";
    
    // Add all measurement resource blocks at the end
    $ttl .= $measurementBlocks;
    
    // Add additional resource declarations
    if (!empty($additionalDeclarations)) {
        $ttl .= $additionalDeclarations;
    }
    
    // Debug log the final TTL
// At the end of processArrowheadFormData(), add:
error_log('FINAL TTL GENERATED: ' . $ttl, 3, OMEKA_PATH . '/logs/final-ttl-debug.log');    
    return $ttl;

}






    private function updateItemSetWithExcavationInfo($itemSetId, $excavationData) {
        if (!$itemSetId || empty($excavationData)) {
            return false;
        }
        
        try {
            // Prepare the update data
            $updateData = [];
            
            // Add description if location is available
            if (!empty($excavationData['location'])) {
                $updateData['dcterms:description'] = [
                    [
                        'type' => 'literal',
                        'property_id' => 4,
                        '@value' => "Archaeological excavation at " . $excavationData['location']
                    ]
                ];
            }
            
            // Add archaeologist as creator if available
            if (!empty($excavationData['archaeologist'])) {
                $updateData['dcterms:creator'] = [
                    [
                        'type' => 'literal',
                        'property_id' => 7, // Dublin Core Creator
                        '@value' => $excavationData['archaeologist']
                    ]
                ];
            }
            
            // Execute the update if we have data to update
            if (!empty($updateData)) {
                $updateResult = $this->api()->update(
                    'item_sets', 
                    $itemSetId, 
                    $updateData, 
                    [], 
                    ['isPartial' => true]
                );
                
                return $updateResult ? true : false;
            }
            
        } catch (\Exception $e) {
            error_log('Failed to update item set with excavation info: ' . $e->getMessage(), 3, OMEKA_PATH . '/logs/excavation-update.log');
            return false;
        }
        
        return true;
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
    

    private function uploadTtlDataWithMedia($ttlData, $itemSetId, $uploadedFiles) {
        // Store files temporarily
        $this->uploadedFiles = $uploadedFiles;
        
        // Call the regular upload method
        return $this->uploadTtlData($ttlData, $itemSetId);
    }

    public function processCollectingFormAction()
    {
        // Get the item set ID and upload type from query parameters
        $itemSetId = $this->params()->fromQuery('item_set_id');
        $uploadType = $this->params()->fromQuery('upload_type', 'arrowhead');
        
        // Get all POST data from the collecting form
        $formData = $this->params()->fromPost();
        
        error_log('Received collecting form data: ' . print_r($formData, true), 3, OMEKA_PATH . '/logs/collecting-form.log');
        
        // STORE UPLOADED FILES IMMEDIATELY
        $uploadedFiles = null;
        if (isset($_FILES['file']['54'])) {
            $uploadedFiles = $_FILES['file']['54'];
            error_log('Found uploaded files: ' . print_r($uploadedFiles, true), 3, OMEKA_PATH . '/logs/collecting-form.log');
        }
        
        // Transform collecting form data to format expected by processArrowheadFormData
        $arrowheadData = $this->transformCollectingFormToArrowheadData($formData);
        
    // Process the transformed data
    if (!empty($arrowheadData)) {
        $ttlData = $this->processArrowheadFormData($arrowheadData, $itemSetId);
        // MODIFY THIS LINE - pass the uploaded files
        $result = $this->uploadTtlDataWithMedia($ttlData, $itemSetId, $uploadedFiles);        
        error_log('Processed collecting form data: ' . $result, 3, OMEKA_PATH . '/logs/collecting-form.log');
        
        // Redirect back to excavation context with success message
        return $this->redirect()->toUrl($this->url()->fromRoute('site/add-triplestore/upload', [
            'site-slug' => $this->currentSite()->slug(),
        ], [
            'query' => [
                'upload_type' => 'arrowhead',
                'item_set_id' => $itemSetId,
                'mode' => 'form',
                'result' => $result,
                'success' => '1'
            ]
        ]));
    }
    
    // If transformation failed, redirect with error
    return $this->redirect()->toUrl($this->url()->fromRoute('site/add-triplestore/upload', [
        'site-slug' => $this->currentSite()->slug(),
    ], [
        'query' => [
            'upload_type' => 'arrowhead',
            'item_set_id' => $itemSetId,
            'mode' => 'form',
            'result' => 'Error: Could not process form data'
        ]
    ]));
}

/**
 * Transform data from Collecting module format to format needed for triplestore
 * Enhanced to include archaeological context selections
 */
private function transformCollectingFormToArrowheadData($formData)
{
    error_log('RAW COLLECTING FORM DATA: ' . print_r($formData, true), 3, OMEKA_PATH . '/logs/debug-all-fields.log');

    $arrowheadData = [];
    
    // Updated field mappings based on your actual form structure
    $fieldMappings = [
        'prompt_53' => 'arrowhead_identifier',    // ID field 
        'prompt_54' => 'images',          // Type field
        'prompt_55' => 'arrowhead_annotation',    // Observations/annotations
        'prompt_56' => 'condition_state',         // Complete/Broken
        'prompt_65' => 'arrowhead_type',          // Elongate/Short
        'prompt_69' => 'arrowhead_variant',       // Flat/Raised/Thick
        'prompt_70' => 'arrowhead_shape',         // Triangle/Losangular/Stemmed
        'prompt_71' => 'point_definition',        // Sharp/Fractured
        'prompt_72' => 'body_symmetry',           // Symmetrical/Non-symmetrical
        'prompt_73' => 'arrowhead_base',          // Base type
        'prompt_93' => 'arrowhead_material',      // Material
        
        // Measurements with values and units
        'prompt_57' => 'weight',                  // Weight value
        'prompt_58' => 'weight_unit',             // Weight unit
        'prompt_59' => 'height',                  // Height value
        'prompt_60' => 'height_unit',             // Height unit
        'prompt_61' => 'width',                   // Width value
        'prompt_62' => 'width_unit',              // Width unit
        'prompt_63' => 'thickness',               // Thickness value
        'prompt_64' => 'thickness_unit',          // Thickness unit
        'prompt_74' => 'body_length',             // Body length value
        'prompt_75' => 'body_length_unit',        // Body length unit
        'prompt_76' => 'base_length',             // Base length value
        'prompt_77' => 'base_length_unit',        // Base length unit
        
        // Elongation index
        'prompt_66' => 'elongation_index',        // Medium/Elongated/Short
        
        // Chipping properties
        'prompt_78' => 'chipping_mode',           // Plane/Parallel/Sub-parallel
        'prompt_79' => 'chipping_amplitude',      // Marginal/Deep
        'prompt_80' => 'chipping_direction',      // Direct/Reverse/Bifacial
        'prompt_81' => 'chipping_orientation',    // Side/Transverse
        'prompt_82' => 'chipping_delineation',    // Continuous/Composite/Denticulated
        'prompt_83' => 'chipping_location_lateral_1',    // Distal/Median/Proximal
        'prompt_84' => 'chipping_location_lateral_2',
        'prompt_85' => 'chipping_location_lateral_3',
        'prompt_86' => 'chipping_location_transversal_1', // Distal/Median/Proximal
        'prompt_87' => 'chipping_location_transversal_2',
        'prompt_88' => 'chipping_location_transversal_3',
        'prompt_89' => 'chipping_shape',          // Straight/Convex/Concave/Sinuous
        
        // Square coordinates
        'prompt_90' => 'x_coordinate',
        'prompt_91' => 'y_coordinate',
        'prompt_92' => 'z_coordinate',

        'prompt_94' => 'x_coordinate_unit',     // X coordinate unit
        'prompt_95' => 'y_coordinate_unit',     // Y coordinate unit  
        'prompt_96' => 'z_coordinate_unit',     // Z coordinate unit

        // gps coordinates
        'prompt_67' => 'gps_latitude',          // GPS latitude
        'prompt_68' => 'gps_longitude',         // GPS longitude
    ];
    
    // Process the mapping
    foreach ($fieldMappings as $collectingField => $arrowheadField) {
        if (isset($formData[$collectingField]) && !empty($formData[$collectingField])) {
            $value = $formData[$collectingField];
            
            // Clean up boolean values from collecting form
            if (strpos($value, 'True') === 0) {
                $arrowheadData[$arrowheadField] = 'true';
            } elseif (strpos($value, 'False') === 0) {
                $arrowheadData[$arrowheadField] = 'false';
            } else {
                $arrowheadData[$arrowheadField] = $value;
            }
        }
    }

    // ENHANCED: Process archaeological context selections
    // These come from the form template's archaeological context section
    if (!empty($formData['selected_square'])) {
        $arrowheadData['selected_square'] = $formData['selected_square'];
        error_log('Added selected square: ' . $formData['selected_square'], 3, OMEKA_PATH . '/logs/context-debug.log');
    }
    
    if (!empty($formData['selected_context'])) {
        $arrowheadData['selected_context'] = $formData['selected_context'];
        error_log('Added selected context: ' . $formData['selected_context'], 3, OMEKA_PATH . '/logs/context-debug.log');
    }
    
    if (!empty($formData['selected_svu'])) {
        $arrowheadData['selected_svu'] = $formData['selected_svu'];
        error_log('Added selected SVU: ' . $formData['selected_svu'], 3, OMEKA_PATH . '/logs/context-debug.log');
    }

    // Handle file uploads for images
    if (isset($formData['file']['54']) && is_array($formData['file']['54'])) {
        $imageFiles = $formData['file']['54'];
        error_log('Image files: ' . print_r($imageFiles, true), 3, OMEKA_PATH . '/logs/image-files.log');
        $imageUrls = [];
        
        foreach ($imageFiles as $imageFile) {
            if (!empty($imageFile)) {
                // Process uploaded file and create URL
                // For now, we'll create a placeholder URL structure
                $baseUrl = "https://purl.org/megalod/images/";
                $filename = basename($imageFile);
                $imageUrls[] = $baseUrl . $filename;
            }
        }
        
        if (!empty($imageUrls)) {
            $arrowheadData['images'] = $imageUrls;
        }
    }

    // Remove unit fields if their corresponding value fields are empty
    $valuesToCheck = [
        'thickness' => 'thickness_unit',
        'body_length' => 'body_length_unit', 
        'base_length' => 'base_length_unit'
    ];
    
    foreach ($valuesToCheck as $valueField => $unitField) {
        if (empty($arrowheadData[$valueField]) && isset($arrowheadData[$unitField])) {
            unset($arrowheadData[$unitField]);
            error_log("Removed orphaned unit field: $unitField", 3, OMEKA_PATH . '/logs/debug-all-fields.log');
        }
    }

    // Log the transformed data for debugging
    error_log('TRANSFORMED ARROWHEAD DATA: ' . print_r($arrowheadData, true), 3, OMEKA_PATH . '/logs/debug-all-fields.log');

    return $arrowheadData;
}



// Call this function at the very beginning of transformCollectingFormToArrowheadData:
// $this->debugDetailedPromptProcessing($formData);

/**
 * Prepare TTL data from excavation form submissions with the new data model
 */
private function prepareTtlFromExcavationData($excavationId, $excavationData, $contextData, $svuData, $encounterData)
{
    // Ensure we have a valid excavation ID with proper prefix
    if (empty($excavationId) || $excavationId === "") {
        $excavationId = "EXC-" . uniqid();
    } else if (strpos($excavationId, 'EXC-') !== 0) {
        // Ensure excavation ID has the right prefix
        $excavationId = "EXC-" . $excavationId;
    }
    
    // Generate base URIs for the resources
    $baseUri = $this->baseDataGraphUri;
    $graphId = preg_replace('/[^a-zA-Z0-9]/', '', $excavationId); // Clean ID for URI
    $excavationUri = "$baseUri$graphId/excavation/$excavationId";
    
    // Create context with proper ID - never use the excavation ID for the context
    $contextId = null;
    if ($contextData && !$contextData['isExisting']) {
        $contextId = $contextData['data']['id'];
    } else {
        $contextId = 'CTX-' . uniqid();
    }
    
    $contextUri = "$baseUri$graphId/context/$contextId";
    
    // Create SVU with proper ID
    $svuId = ($svuData && !$svuData['isExisting']) 
        ? $svuData['data']['id'] 
        : 'SVU-' . uniqid();
    $svuUri = "$baseUri$graphId/svu/$svuId";
    
    // Use existing URIs if provided
    if ($contextData && $contextData['isExisting']) {
        $contextUri = $contextData['uri'];
    }
    if ($svuData && $svuData['isExisting']) {
        $svuUri = $svuData['uri'];
    }
    
    // Start building the TTL
    $ttl = $this->getTtlPrefixes();
    
    // Add excavation with required link to context
    $ttl .= "<$excavationUri> a excav:Excavation;\n";
    $ttl .= "    dct:identifier \"$excavationId\"^^xsd:literal;\n";
    $ttl .= "    excav:hasContext <$contextUri>;\n";
    
    // Add location information if available
    if (!empty($excavationData['location_name'])) {
        $locationUri = "$baseUri$graphId/location/" . $this->sanitizeForUri($excavationData['location_name']);
        $ttl .= "    dul:hasLocation <$locationUri>;\n";
    }
    
    // Add archaeologist information if available
    if (!empty($excavationData['archaeologist_name']) || !empty($excavationData['orcid'])) {
        $archaeologistId = !empty($excavationData['orcid']) ? 
            $this->sanitizeForUri($excavationData['orcid']) : 
            $this->sanitizeForUri($excavationData['archaeologist_name'] ?: 'unknown');
        $archaeologistUri = "$baseUri$graphId/archaeologist/$archaeologistId";
        $ttl .= "    excav:hasPersonInCharge <$archaeologistUri>;\n";
    }
    
    $ttl .= "    .\n\n";
    
    // Add context with required ID and link to SVU (if provided)
    $ttl .= "<$contextUri> a excav:Context;\n"; 
    $ttl .= "    dct:identifier \"$contextId\"^^xsd:literal;\n";
    
    // Only add hasSVU if SVU data exists
    if ($svuData) {
        $ttl .= "    excav:hasSVU <$svuUri>;\n";
    }
    
    // Add description if available
    if ($contextData && !$contextData['isExisting'] && !empty($contextData['data']['description'])) {
        $ttl .= "    dct:description \"" . $contextData['data']['description'] . "\"^^xsd:literal;\n";
    }
    
    $ttl .= "    .\n\n";
    
    // Add location if provided
    if (!empty($excavationData['location_name'])) {
        $locationUri = "$baseUri$graphId/location/" . $this->sanitizeForUri($excavationData['location_name']);
        $ttl .= "<$locationUri> a excav:Location;\n";
        $ttl .= "    dbo:informationName \"" . $excavationData['location_name'] . "\"^^xsd:literal;\n";
        
        // Add GPS coordinates if provided
        if (!empty($excavationData['latitude']) && !empty($excavationData['longitude'])) {
            $gpsUri = "$locationUri/gps";
            $ttl .= "    excav:hasGPSCoordinates <$gpsUri>;\n";
        }
        
        // Add District and Parish if provided
        if (!empty($excavationData['District'])) {
            $districtUri = "$baseUri$graphId/District/" . $this->sanitizeForUri($excavationData['District']);
            $ttl .= "    dbo:District <$districtUri>;\n";
        }
        
        if (!empty($excavationData['Parish'])) {
            $parishUri = "$baseUri$graphId/Parish/" . $this->sanitizeForUri($excavationData['Parish']);
            $ttl .= "    dbo:Parish <$parishUri>;\n";
        }
        
        if (!empty($excavationData['Country'])) {
            $countryUri = "http://dbpedia.org/resource/" . $this->sanitizeForUri($excavationData['Country']);
            $ttl .= "    dbo:Country <$countryUri>;\n";
        }
        
        $ttl .= "    .\n\n";
        
        // Add GPS coordinates if provided
        if (!empty($excavationData['latitude']) && !empty($excavationData['longitude'])) {
            $ttl .= "<$gpsUri> a excav:GPSCoordinates;\n";
            $ttl .= "    geo:lat " . $excavationData['latitude'] . ";\n";
            $ttl .= "    geo:long " . $excavationData['longitude'] . ";\n";
            $ttl .= "    .\n\n";
        }
        
        // Add District if provided
        if (!empty($excavationData['District'])) {
            $ttl .= "<$districtUri> a dbo:District;\n";
            $ttl .= "    dbo:informationName \"" . $excavationData['District'] . "\"^^xsd:literal;\n";
            $ttl .= "    .\n\n";
        }
        
        // Add Parish if provided
        if (!empty($excavationData['Parish'])) {
            $ttl .= "<$parishUri> a dbo:Parish;\n";
            $ttl .= "    dbo:informationName \"" . $excavationData['Parish'] . "\"^^xsd:literal;\n";
            $ttl .= "    .\n\n";
        }
    }
    
    // Add archaeologist if provided
    if (!empty($excavationData['archaeologist_name']) || !empty($excavationData['orcid'])) {
        $archaeologistId = !empty($excavationData['orcid']) ? 
            $this->sanitizeForUri($excavationData['orcid']) : 
            $this->sanitizeForUri($excavationData['archaeologist_name'] ?: 'unknown');
        $archaeologistUri = "$baseUri$graphId/archaeologist/$archaeologistId";
        
        $ttl .= "<$archaeologistUri> a excav:Archaeologist;\n";
        
        if (!empty($excavationData['archaeologist_name'])) {
            $ttl .= "    foaf:name \"" . $excavationData['archaeologist_name'] . "\"^^xsd:literal;\n";
        }
        
        if (!empty($excavationData['orcid'])) {
            $ttl .= "    foaf:account <https://orcid.org/" . $excavationData['orcid'] . ">;\n";
        }
        
        if (!empty($excavationData['archaeologist_email'])) {
            $ttl .= "    foaf:mbox <mailto:" . $excavationData['archaeologist_email'] . ">;\n";
        }
        
        $ttl .= "    .\n\n";
    }
    
    // Add SVU if provided
    if ($svuData) {
        $ttl .= "<$svuUri> a excav:StratigraphicVolumeUnit;\n";
        $ttl .= "    dct:identifier \"$svuId\"^^xsd:literal;\n";
        
        // Add description if available
        if ($svuData && !$svuData['isExisting'] && !empty($svuData['data']['description'])) {
            $ttl .= "    dct:description \"" . $svuData['data']['description'] . "\"^^xsd:literal;\n";
        }
        
        // Add timeline if year data is provided
        if ($svuData && !$svuData['isExisting'] && 
            (!empty($svuData['data']['lower_year']) || !empty($svuData['data']['upper_year']))) {
            $timelineUri = "$svuUri/timeline";
            $ttl .= "    excav:hasTimeline <$timelineUri>;\n";
            $ttl .= "    .\n\n";
            
            // Add timeline
            $ttl .= "<$timelineUri> a excav:TimeLine;\n";
            
            if (!empty($svuData['data']['lower_year'])) {
                $lowerInstantUri = "$timelineUri/beginning";
                $ttl .= "    time:hasBeginning <$lowerInstantUri>;\n";
            }
            
            if (!empty($svuData['data']['upper_year'])) {
                $upperInstantUri = "$timelineUri/end";
                $ttl .= "    time:hasEnd <$upperInstantUri>;\n";
            }
            
            $ttl .= "    .\n\n";
            
            // Add instants
            if (!empty($svuData['data']['lower_year'])) {
                $ttl .= "<$lowerInstantUri> a excav:Instant;\n";
                $ttl .= "    time:inXSDgYear \"" . $svuData['data']['lower_year'] . "\"^^xsd:gYear;\n";
                
                // Use the new BCAD URI structure
                $bcacValue = $svuData['data']['lower_bc'] ? "BC" : "AC";
                $ttl .= "    excav:bcad <https://purl.org/megalod/kos/MegaLOD-BCAD/$bcacValue>;\n";
                $ttl .= "    .\n\n";
            }
            
            if (!empty($svuData['data']['upper_year'])) {
                $ttl .= "<$upperInstantUri> a excav:Instant;\n";
                $ttl .= "    time:inXSDgYear \"" . $svuData['data']['upper_year'] . "\"^^xsd:gYear;\n";
                
                // Use the new BCAD URI structure
                $bcacValue = $svuData['data']['upper_bc'] ? "BC" : "AC";
                $ttl .= "    excav:bcad <https://purl.org/megalod/kos/MegaLOD-BCAD/$bcacValue>;\n";
                $ttl .= "    .\n\n";
            }
        } else {
            $ttl .= "    .\n\n";
        }
    }
    
    // Add encounter event if provided
    if ($encounterData) {
        $encounterUri = "$baseUri$graphId/encounter/" . uniqid();
        if ($encounterData['isExisting']) {
            $encounterUri = $encounterData['uri'];
        }
        
        $ttl .= "<$encounterUri> a excav:EncounterEvent;\n";
        
        if (!$encounterData['isExisting']) {
            if (!empty($encounterData['data']['date'])) {
                $ttl .= "    dct:date \"" . $encounterData['data']['date'] . "\"^^xsd:literal;\n";
            } else {
                // Default to current date if not provided
                $ttl .= "    dct:date \"" . date('Y-m-d') . "\"^^xsd:literal;\n";
            }
            
            if (!empty($encounterData['data']['depth'])) {
                // Create depth resource
                $depthUri = "$encounterUri/depth";
                $ttl .= "    schema:depth <$depthUri>;\n";
            }
        }
        
        $ttl .= "    excav:foundInExcavation <$excavationUri>;\n";
        $ttl .= "    excav:foundInContext <$contextUri>;\n";
        
        if ($svuData) {
            $ttl .= "    excav:foundInSVU <$svuUri>;\n";
        }
        
        $ttl .= "    .\n\n";
        
        // Add depth details if provided
        if (!$encounterData['isExisting'] && !empty($encounterData['data']['depth'])) {
            $depthUri = "$encounterUri/depth";
            $ttl .= "<$depthUri> a excav:Depth;\n";
            $ttl .= "    schema:value \"" . $encounterData['data']['depth'] . "\"^^xsd:decimal;\n";
            $ttl .= "    schema:UnitCode <http://qudt.org/vocab/unit/CMNT>;\n";
            $ttl .= "    .\n\n";
        }
    }

    return $ttl;
}
/**
 * Process entity selection for Context, SVU, and EncounterEvent
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
/*private function generateContextTtl($contextUri, $contextData, $svuUri = null)
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
}*/

/**
 * Generate TTL for a Stratigraphic Volume Unit (SVU)
 */
/*private function generateSvuTtl($svuUri, $svuData)
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
}*/

/**
 * Generate TTL for an encounter event
 */
/*private function generateEncounterTtl($encounterUri, $encounterData, $excavationUri, $contextUri = null, $svuUri = null)
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
}*/

/**
 * Generate TTL for a location
 */
/*private function generateLocationTtl($locationUri, $locationName)
{
    $ttl = "";
    $ttl .= "<$locationUri> a dbo:Place;\n";
    $ttl .= "    dbo:informationName \"$locationName\"^^xsd:string;\n";
    
    // Add placeholder District and Parish if needed
    $districtUri = $locationUri . "/District";
    $parishUri = $locationUri . "/Parish";
    
    $ttl .= "    dbo:District <$districtUri>;\n";
    $ttl .= "    dbo:Parish <$parishUri>;\n";
    
    // Add placeholder coordinates
    $coordinatesUri = $locationUri . "/coordinates";
    $ttl .= "    excav:hasGPSCoordinates <$coordinatesUri>;\n";
    $ttl .= "    .\n\n";
    
    // Add District
    $ttl .= "<$districtUri> a dbo:District;\n";
    $ttl .= "    dbo:informationName \"Unknown District\"^^xsd:string;\n";
    $ttl .= "    .\n\n";
    
    // Add Parish
    $ttl .= "<$parishUri> a dbo:Parish;\n";
    $ttl .= "    dbo:informationName \"Unknown Parish\"^^xsd:string;\n";
    $ttl .= "    .\n\n";
    
    // Add coordinates
    $ttl .= "<$coordinatesUri> a geo:SpatialThing;\n";
    $ttl .= "    geo:lat \"0.0\"^^xsd:decimal;\n";
    $ttl .= "    geo:long \"0.0\"^^xsd:decimal;\n";
    $ttl .= "    .\n\n";
    
    return $ttl;
}*/

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
        if (empty($file['tmp_name'])) {
            return 'No file uploaded or file upload error.';
        }
    
        $fileExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $fileType = $file['type'];
    
        if (strtolower($fileExtension) === 'ttl' && $fileType !== 'application/x-turtle') {
            $fileType = 'application/x-turtle';
        }
    
        if (!in_array($fileType, ['application/x-turtle', 'application/xml', 'text/xml'])) {
            return 'Invalid file type. Please upload a valid .ttl or .xml file.';
        }

        error_log('File type: ' . $fileType, 3, OMEKA_PATH . '/logs/file-upload.log');
    
        try {
            if ($fileType === 'application/xml' || $fileType === 'text/xml') {
                $rdfXmlData = $this->xmlParser($file);
                if (is_string($rdfXmlData) && strpos($rdfXmlData, 'Failed') === false) {
                    $ttlData = $this->xmlTtlConverter($rdfXmlData);
                } else {
                    throw new \Exception('Failed to process XML file: ' . $rdfXmlData);
                }
            } else {
                $ttlData = file_get_contents($file['tmp_name']);
            }

            error_log('File tmp_name: ' . $file['tmp_name'], 3, OMEKA_PATH . '/logs/file-upload.log');
            error_log('File exists check: ' . (file_exists($file['tmp_name']) ? 'exists' : 'does not exist'), 3, OMEKA_PATH . '/logs/file-upload.log');
    
            // Skip validation if not explicitly required - for continuous uploads
            // to avoid unnecessary error messages
            if ($uploadType) {
                try {
                    $this->validateUploadType($ttlData, $uploadType);
                } catch (\Exception $e) {
                    // Type mismatch but not critical for continuous upload
                    error_log('Upload type validation warning: ' . $e->getMessage());
                }
            }
            error_log('TTL data: ' . $ttlData, 3, OMEKA_PATH . '/logs/file-upload.log');
            error_log('sending to uploadTtlData', 3, OMEKA_PATH . '/logs/file-upload.log');
    
            $result = $this->uploadTtlData($ttlData, $itemSetId);
            return $result;
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
    error_log('Checking if this is excavation data', 3, OMEKA_PATH . '/logs/auxNew.log');
    $isExcavation = false;
    $excavationIdentifier = "0"; // Default to "0" graph

    try {
        $this->validateUploadType($ttlData, 'excavation');
        error_log('Upload excav validation passed', 3, OMEKA_PATH . '/logs/a.log');
        $isExcavation = true;
        // Extract excavation identifier for graph organization
        $extractedId = $this->extractExcavationIdentifier($ttlData);
        if ($extractedId) {
            $excavationIdentifier = $extractedId;
        }
        error_log('Extracted excavation identifier: ' . $excavationIdentifier, 3, OMEKA_PATH . '/logs/excavation-debug-final.log');
    } catch (\Exception $e) {
        // If validation fails, it means the data is not excavation data
        error_log('Validation for excavation failed, this is an arrowhead', 3, OMEKA_PATH . '/logs/auxNew.log');
        
        // Check if this item belongs to an excavation item set
        if ($itemSetId) {
            error_log('Item set ID provided: ' . $itemSetId, 3, OMEKA_PATH . '/logs/auxNew.log');
            $excavationId = $this->getExcavationIdentifierFromItemSet($itemSetId);
            if ($excavationId) {
                $excavationIdentifier = $excavationId;
                error_log('Using excavation ID from item set: ' . $excavationIdentifier, 3, OMEKA_PATH . '/logs/excavation-debug.log');
            }
        }
    }

    // Normalize URIs based on context
    if ($itemSetId) {
        $ttlData = $this->normalizeUris($ttlData, $itemSetId);
        error_log('URIs normalized for item set ID: ' . $itemSetId, 3, OMEKA_PATH . '/logs/uri-normalize.log');
    } elseif ($isExcavation && $excavationIdentifier) {
        // SINGLE POINT OF ITEM SET CREATION FOR EXCAVATIONS
        if ($this->excavationIdentifierExists($excavationIdentifier)) {
            // Optionally handle duplicate excavation identifiers
            // For now, we'll proceed but log a warning
            error_log('Warning: Excavation identifier already exists: ' . $excavationIdentifier, 3, OMEKA_PATH . '/logs/excavation-debug.log');
        }
        
        // Extract excavation metadata
        $excavationMetadata = $this->extractExcavationMetadataFromTtl($ttlData);
        
        try {
            // Create item set with proper metadata - SINGLE CREATION POINT
            $itemSetTitle = "Excavation $excavationIdentifier";
            $itemSetDescription = $excavationMetadata['location'] ? 
                "Archaeological excavation at " . $excavationMetadata['location'] : 
                "Archaeological excavation with identifier $excavationIdentifier";
            
            $response = $this->api()->create('item_sets', [
                'dcterms:title' => [
                    [
                        'type' => 'literal',
                        'property_id' => 1,
                        '@value' => $itemSetTitle
                    ]
                ],
                'dcterms:description' => [
                    [
                        'type' => 'literal',
                        'property_id' => 4,
                        '@value' => $itemSetDescription
                    ]
                ],
                'dcterms:creator' => $excavationMetadata['archaeologist'] ? [
                    [
                        'type' => 'literal',
                        'property_id' => 7665,
                        '@value' => $excavationMetadata['archaeologist']
                    ]
                ] : [],
                'o:is_public' => true
            ]);
            
            // If successful, get the new item set ID
            if ($response) {
                $newItemSet = $response->getContent();
                $itemSetId = $newItemSet->id();
                
                error_log('Successfully created single item set with ID: ' . $itemSetId, 3, OMEKA_PATH . '/logs/excavation-debug.log');
                
                // Now normalize the URIs with the new item set ID
                $ttlData = $this->normalizeUris($ttlData, $itemSetId);
                error_log('URIs normalized for excavation with item set ID: ' . $itemSetId, 3, OMEKA_PATH . '/logs/uri-normalize.log');
                
                // Store the mapping between item set and excavation
                $this->storeMappingBetweenItemSetAndExcavation($itemSetId, $excavationIdentifier);
            }
        } catch (\Exception $e) {
            error_log('Error creating item set for excavation: ' . $e->getMessage(), 3, OMEKA_PATH . '/logs/excavation-debug.log');
            return 'Error: Failed to create excavation item set - ' . $e->getMessage();
        }
    }

    error_log('is excavation: ' . ($isExcavation ? 'true' : 'false'), 3, OMEKA_PATH . '/logs/excavation-debug.log');

    // Now proceed with the regular upload process
    // First, upload to GraphDB with the excavation identifier if available
    $graphDbResult = $this->sendToGraphDB($ttlData, $itemSetId);
    error_log('GraphDB upload result: ' . $graphDbResult, 3, OMEKA_PATH . '/logs/auxNew.log');
    
    if (strpos($graphDbResult, 'successfully') !== false) {
        // If GraphDB upload is successful, then process in Omeka S
        $omekaData = $this->transformTtlToOmekaSData($ttlData, $itemSetId);

        $omekaResponse = $this->sendToOmekaS($omekaData, $itemSetId);
        
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
                return "Data uploaded successfully to both GraphDB and Omeka S. Created Item Set #{$itemSetId} for excavation '$excavationIdentifier' and " . 
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


private function extractExcavationMetadataFromTtl($ttlData) {
    $metadata = [
        'location' => null,
        'archaeologist' => null
    ];
    
    // Extract location name
    if (preg_match('/dbo:informationName\s+"([^"]+)"/i', $ttlData, $matches)) {
        $metadata['location'] = $matches[1];
    }
    
    // Extract archaeologist name  
    if (preg_match('/foaf:name\s+"([^"]+)"/i', $ttlData, $matches)) {
        $metadata['archaeologist'] = $matches[1];
    }
    
    return $metadata;
}


/**
 * Check if an excavation with the given identifier already exists
 * 
 * @param string $excavationIdentifier The excavation identifier to check
 * @return bool True if the excavation identifier already exists, false otherwise
 */
private function excavationIdentifierExists($excavationIdentifier) {
    if (empty($excavationIdentifier)) {
        return false;
    }
    
    try {
        // Search for items with the given excavation identifier
        $response = $this->api()->search('items', [
            'property' => [
                [
                    'property' => 10, // Assuming 10 is the property ID for dcterms:identifier
                    'type' => 'eq',
                    'text' => $excavationIdentifier
                ]
            ]
        ]);
        
        // If we found any items, the identifier exists
        return $response->getTotalResults() > 0;
        
    } catch (\Exception $e) {
        error_log('Error checking if excavation identifier exists: ' . $e->getMessage(), 3, OMEKA_PATH . '/logs/excavation-validation.log');
        return false; // Assume it doesn't exist in case of error
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
private function extractExcavationIdentifier(string $ttlData): ?string {
    // First try to find dcterms:identifier
    if (preg_match('/dct:identifier\s+"([^"]+)"\^\^xsd:literal\s*;/', $ttlData, $matches)) {
        return $matches[1];
    }
    
    // Try alternative pattern for identifier
    if (preg_match('/dcterms:identifier\s+"([^"]+)"/', $ttlData, $matches)) {
        return $matches[1];
    }
    
    // Try looking for identifier after a colon (common format in TTL)
    if (preg_match('/identifier:\s+"([^"]+)"/', $ttlData, $matches)) {
        return $matches[1];
    }
    
    return null;
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

    error_log('ttlData: ' . $ttlData, 3, OMEKA_PATH . '/logs/a.log');
    $excavationPatterns = [
        'a excav:Excavation',
        'crmarchaeo:A9_Archaeological_Excavation',
        'a crmarchaeo:A9_Archaeological_Excavation'
    ];
    $arrowheadPatterns = [
        'a ah:Arrowhead',
        'a crm:E24_Physical_Man-Made_Thing',
        '<https://purl.org/megalod/ms/ah/Arrowhead',
        'ah:shape',
        'ah:variant',
        'ah:hasMorphology'
    ];
    $isExcavation = false;
    foreach ($excavationPatterns as $pattern) {
        if (strpos($ttlData, $pattern) !== false) {
            $isExcavation = true;
            break;
        }
    }
    $isArrowhead = false;
    foreach ($arrowheadPatterns as $pattern) {
        if (strpos($ttlData, $pattern) !== false) {
            $isArrowhead = true;
            break;
        }
    }
    if ($uploadType === 'excavation' && !$isExcavation) {
        // Check if it's actually an arrowhead being uploaded to an excavation context
        if ($isArrowhead) {
            error_log('Arrowhead data detected when expecting excavation, but this is allowed for item sets', 3, OMEKA_PATH . '/logs/validation.log');
            return; // Allow arrowheads to be added to excavation context
        }
        throw new \Exception('Invalid data type for excavation upload.');
    } elseif ($uploadType === 'arrowhead' && !$isArrowhead) {
        throw new \Exception('Invalid data type for Arrowhead upload.');
    }
    
    error_log('Data validation passed: ' . ($isExcavation ? 'Excavation' : 'Arrowhead'), 3, OMEKA_PATH . '/logs/validation.log');
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
            '/<dbo:District rdf:resource="([^"]+)"\/>/' => 'dbo:District <$1>;',
            '/<dbo:Parish rdf:resource="([^"]+)"\/>/' => 'dbo:Parish <$1>;',
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

    private function sendToGraphDB($data, $excavationId)
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
              GRAPH <http://rdf4j.org/schema/rdf4j#SHACLShapeGraph> {
                ?shape a sh:NodeShape .
              }
              GRAPH <$graphUri> {
                ?focusNode ?predicate ?object .
              }
              FILTER EXISTS {
                  GRAPH <http://rdf4j.org/schema/rdf4j#SHACLShapeGraph> {
                    ?shape sh:targetClass ?targetClass .
                    FILTER NOT EXISTS { ?focusNode a ?targetClass }
                  }
              }
              FILTER EXISTS {
                  GRAPH <http://rdf4j.org/schema/rdf4j#SHACLShapeGraph> {
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





private function transformTtlToOmekaSData($ttlData, $itemSetId = null): array {
    error_log('Transforming TTL to Omeka S data', 3, OMEKA_PATH . '/logs/transform.log');
    $graph = new \EasyRdf\Graph();
    $graph->parse($ttlData, 'turtle');
    
    $omekaData = [];
    $rdfData = $graph->toRdfPhp();

    error_log('RDF Data subjects found: ' . count($rdfData), 3, OMEKA_PATH . '/logs/transform.log');
    
    // Find main subjects (items that should become Omeka items)
    $mainSubjects = $this->identifyMainSubjects($rdfData, $itemSetId);
    
    // Get excavation identifier for context
    $excavationId = "0"; // Default
    if ($itemSetId) {
        $mappedId = $this->getExcavationIdentifierFromItemSet($itemSetId);
        if ($mappedId) {
            $excavationId = $mappedId;
        }
    }
    
    // Process each main subject as a separate Omeka item
    foreach ($mainSubjects as $subject => $subjectType) {
        $itemData = [
            'o:resource_class' => ['o:id' => 1], // Default Item Resource Class ID
            'o:item_set' => [],
        ];
        
        // Add item to item set if provided
        if ($itemSetId) {
            $itemData['o:item_set'][] = ['o:id' => $itemSetId];
        }
        
        // Extract identifier and create title
        $identifier = $this->extractIdentifier($rdfData, $subject);
        if ($identifier) {
            $itemData['dcterms:identifier'] = [
                [
                    'type' => 'literal',
                    'property_id' => 10,
                    '@value' => $identifier
                ]
            ];
        }
        
        // Set title
        $itemType = $this->determineItemType($subjectType);
        $title = $itemType;
        if ($identifier) {
            $title .= " " . $identifier;
            if ($excavationId != "0") {
                $title .= " (Excavation $excavationId)";
            }
        }
        
        $itemData['dcterms:title'] = [
            [
                'type' => 'literal',
                'property_id' => 1,
                '@value' => $title
            ]
        ];
        
        // Extract common properties first
        $this->extractCommonProperties($rdfData, $subject, $itemData);
        
        // Process based on subject type
        switch ($subjectType) {
            case 'arrowhead':
            case 'item':
                $this->processArrowheadData($rdfData, $subject, $itemData);
                break;
            case 'excavation':
                $this->processExcavationData($rdfData, $subject, $itemData);
                break;
            case 'context':
                $this->processContextData($rdfData, $subject, $itemData);
                break;
            case 'svu':
                $this->processSVUData($rdfData, $subject, $itemData);
                break;
            case 'square':
                $this->processSquareData($rdfData, $subject, $itemData);
                break;
        }
        
        $omekaData[] = $itemData;
    }

    error_log('Transformed ' . count($omekaData) . ' items for Omeka S', 3, OMEKA_PATH . '/logs/transform.log');
    
    return $omekaData;
}

/**
 * Identify main subjects that should become Omeka items
 */
private function identifyMainSubjects($rdfData, $itemSetId = null) {
    $subjects = [];
    
    // Define what constitutes a "main" subject (should become an Omeka item)
    $mainSubjectTypes = [
        'https://purl.org/megalod/ms/ah/Arrowhead' => 'arrowhead',
        'https://purl.org/megalod/ms/excavation/Item' => 'item',
        'http://www.cidoc-crm.org/cidoc-crm/E24_Physical_Man-Made_Thing' => 'arrowhead',
        'https://purl.org/megalod/ms/excavation/Excavation' => 'excavation',
        'http://www.cidoc-crm.org/extensions/crmarchaeo/A9_Archaeological_Excavation' => 'excavation',
        'https://purl.org/megalod/ms/excavation/Context' => 'context',
        'https://purl.org/megalod/ms/excavation/StratigraphicVolumeUnit' => 'svu',
        'https://purl.org/megalod/ms/excavation/Square' => 'square'
    ];
    
    // Scan all subjects for type declarations
    foreach ($rdfData as $subject => $predicates) {
        if (isset($predicates['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'])) {
            foreach ($predicates['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'] as $typeObj) {
                if ($typeObj['type'] === 'uri' && isset($mainSubjectTypes[$typeObj['value']])) {
                    $subjects[$subject] = $mainSubjectTypes[$typeObj['value']];
                    break; // Found the type, move to next subject
                }
            }
        }
    }
    
    // If we're in an item set context (adding to existing excavation), prioritize arrowheads
    if ($itemSetId && !empty($subjects)) {
        $arrowheadSubjects = array_filter($subjects, function($type) {
            return in_array($type, ['arrowhead', 'item']);
        });
        
        if (!empty($arrowheadSubjects)) {
            error_log('Found ' . count($arrowheadSubjects) . ' arrowhead subjects for item set ' . $itemSetId, 3, OMEKA_PATH . '/logs/transform.log');
            return $arrowheadSubjects;
        }
    }
    
    // If no subjects found with explicit types, look for subjects with identifiers
    if (empty($subjects)) {
        foreach ($rdfData as $subject => $predicates) {
            if (isset($predicates['http://purl.org/dc/terms/identifier'])) {
                $subjects[$subject] = 'unknown';
            }
        }
    }
    
    error_log('Identified ' . count($subjects) . ' main subjects: ' . implode(', ', array_keys($subjects)), 3, OMEKA_PATH . '/logs/transform.log');
    
    return $subjects;
}

/**
 * Extract the identifier from a subject
 */
private function extractIdentifier($rdfData, $subject) {
    if (isset($rdfData[$subject]['http://purl.org/dc/terms/identifier'])) {
        foreach ($rdfData[$subject]['http://purl.org/dc/terms/identifier'] as $object) {
            if ($object['type'] === 'literal') {
                return $object['value'];
            }
        }
    }
    return null;
}



/*
||||||||||| PROCESS ARROWHEAD DATA |||||||||||

<?php
/**
 * Process arrowhead specific data with complete value extraction
 */
private function processArrowheadData($rdfData, $subject, &$itemData) {
    error_log('Processing arrowhead data for subject: ' . $subject, 3, OMEKA_PATH . '/logs/arrowhead-processing.log');
    
    // Basic properties - direct mapping
    $propertyMap = [
        'https://purl.org/megalod/ms/ah/shape' => ['ArrowHead - shape', 7651],
        'https://purl.org/megalod/ms/ah/variant' => ['ArrowHead - Variant', 7652],
        'http://www.cidoc-crm.org/cidoc-crm/P45_consists_of' => ['is composed of', 478],
        'http://www.cidoc-crm.org/cidoc-crm/E57_Material' => ['is composed of', 480],
        'https://purl.org/megalod/ms/excavation/elongationIndex' => ['Elongation Index', 7676],
        'https://purl.org/megalod/ms/excavation/thicknessIndex' => ['Thickness Index', 7677],
    ];

    // Enhanced resource link mapping
    $resourceLinkMap = [
        'https://purl.org/megalod/ms/excavation/foundInSquare' => ['The Square', 7668],
        'https://purl.org/megalod/ms/excavation/foundInContext' => ['The Encounter Event - an item found in a specific Context', 7672], 
        'https://purl.org/megalod/ms/excavation/foundInSVU' => ['Encounter Event - an item found in a specific Stratigraphic Unit', 7671],
        'https://purl.org/megalod/ms/excavation/foundInExcavation' => ['The Encounter Event - an item found in an Excavation', 7673],
    ];

    $gpsPropertyMap = [
        'http://www.w3.org/2003/01/geo/wgs84_pos#lat' => ['GPS Latitude', 257], // Use your actual property ID for geo:lat
        'http://www.w3.org/2003/01/geo/wgs84_pos#long' => ['GPS Longitude', 259], // Use your actual property ID for geo:long
    ];

    foreach ($gpsPropertyMap as $predicate => $mapping) {
        if (isset($rdfData[$subject][$predicate])) {
            $term = $mapping[0];
            $propertyId = $mapping[1];
            
            if (!isset($itemData[$term])) {
                $itemData[$term] = [];
            }
            
            foreach ($rdfData[$subject][$predicate] as $object) {
                if ($object['type'] === 'literal') {
                    $itemData[$term][] = [
                        'type' => 'literal',
                        'property_id' => $propertyId,
                        '@value' => $object['value']
                    ];
                }
            }
        }
    }
    
    // Process basic properties
    foreach ($propertyMap as $predicate => $mapping) {
        if (isset($rdfData[$subject][$predicate])) {
            $term = $mapping[0];
            $propertyId = $mapping[1];
            
            if (!isset($itemData[$term])) {
                $itemData[$term] = [];
            }
            
            foreach ($rdfData[$subject][$predicate] as $object) {
                if ($object['type'] === 'uri') {
                    // Extract the term from the URI for controlled vocabularies
                    if (strpos($object['value'], '/kos/') !== false) {
                        $parts = explode('/', $object['value']);
                        $value = end($parts);
                        $itemData[$term][] = [
                            'type' => 'literal',
                            'property_id' => $propertyId,
                            '@value' => ucfirst($value)
                        ];
                    } else {
                        $itemData[$term][] = [
                            'type' => 'uri',
                            'property_id' => $propertyId,
                            '@id' => $object['value'],
                            'o:label' => $object['value']
                        ];
                    }
                } else {
                    $itemData[$term][] = [
                        'type' => 'literal',
                        'property_id' => $propertyId,
                        '@value' => $object['value']
                    ];
                }
            }
        }
    }
    
    // Process resource links
    foreach ($resourceLinkMap as $predicate => $mapping) {
        if (isset($rdfData[$subject][$predicate])) {
            $term = $mapping[0];
            $propertyId = $mapping[1];
            
            foreach ($rdfData[$subject][$predicate] as $obj) {
                if ($obj['type'] === 'uri') {
                    $resourceId = $this->extractResourceIdentifier($rdfData, $obj['value']);
                    
                    if ($resourceId) {
                        $linkedItem = $this->findItemByIdentifier($resourceId);
                        
                        if ($linkedItem) {
                            if (!isset($itemData[$term])) {
                                $itemData[$term] = [];
                            }
                            
                            $itemData[$term][] = [
                                'type' => 'resource',
                                'property_id' => $propertyId,
                                'value_resource_id' => $linkedItem->id(),
                                'o:label' => $resourceId
                            ];
                        } else {
                            if (!isset($itemData[$term])) {
                                $itemData[$term] = [];
                            }
                            
                            $itemData[$term][] = [
                                'type' => 'literal',
                                'property_id' => $propertyId,
                                '@value' => $resourceId
                            ];
                        }
                    }
                }
            }
        }
    }

    // Process measurements with proper value extraction
    $this->processMeasurements($rdfData, $subject, $itemData);
    
    // Process morphology data with complete extraction
    $this->processMorphologyData($rdfData, $subject, $itemData);
    
    // Process chipping data with complete extraction
    $this->processChippingData($rdfData, $subject, $itemData);
    
    // Process coordinates
    $this->processCoordinatesData($rdfData, $subject, $itemData);
}

/**
 * Process measurements and extract actual values with units
 */
private function processMeasurements($rdfData, $subject, &$itemData) {
    $measurementMap = [
        'http://schema.org/height' => ['Height', 5616],
        'http://schema.org/width' => ['Width', 5688],
        'http://schema.org/weight' => ['Weight', 5779],
        'http://schema.org/depth' => ['Thickness', 7244],
        'https://purl.org/megalod/ms/ah/hasBodyLength' => ['Body Length', 7678],
        'https://purl.org/megalod/ms/ah/hasBaseLength' => ['Base Length', 7679],
    ];
    
    foreach ($measurementMap as $predicate => $mapping) {
        if (isset($rdfData[$subject][$predicate])) {
            $label = $mapping[0];
            $propertyId = $mapping[1];
            
            foreach ($rdfData[$subject][$predicate] as $measObj) {
                if ($measObj['type'] === 'uri' && isset($rdfData[$measObj['value']])) {
                    $measUri = $measObj['value'];
                    
                    // Extract value and unit
                    $value = $this->extractMeasurementValue($rdfData, $measUri);
                    $unit = $this->extractMeasurementUnit($rdfData, $measUri);
                    
                    if ($value !== null) {
                        $displayValue = $value;
                        if ($unit) {
                            $displayValue .= " " . $unit;
                        }
                        
                        if (!isset($itemData[$label])) {
                            $itemData[$label] = [];
                        }
                        
                        $itemData[$label][] = [
                            'type' => 'literal',
                            'property_id' => $propertyId,
                            '@value' => $displayValue
                        ];
                        
                        error_log("Added measurement: $label = $displayValue", 3, OMEKA_PATH . '/logs/measurements.log');
                    }
                }
            }
        }
    }
}

/**
 * Process morphology data and extract all components
 */
private function processMorphologyData($rdfData, $subject, &$itemData) {
    if (isset($rdfData[$subject]['https://purl.org/megalod/ms/ah/hasMorphology'])) {
        foreach ($rdfData[$subject]['https://purl.org/megalod/ms/ah/hasMorphology'] as $morphObj) {
            if ($morphObj['type'] === 'uri' && isset($rdfData[$morphObj['value']])) {
                $morphUri = $morphObj['value'];
                
                // Extract point definition
                if (isset($rdfData[$morphUri]['https://purl.org/megalod/ms/ah/point'])) {
                    $pointValue = $rdfData[$morphUri]['https://purl.org/megalod/ms/ah/point'][0]['value'];
                    $displayValue = ($pointValue === 'true') ? 'Sharp' : 'Fractured';
                    
                    $itemData['Point Definition'][] = [
                        'type' => 'literal',
                        'property_id' => 7653,
                        '@value' => $displayValue
                    ];
                }
                
                // Extract body symmetry
                if (isset($rdfData[$morphUri]['https://purl.org/megalod/ms/ah/body'])) {
                    $bodyValue = $rdfData[$morphUri]['https://purl.org/megalod/ms/ah/body'][0]['value'];
                    $displayValue = ($bodyValue === 'true') ? 'Symmetrical' : 'Non-symmetrical';
                    
                    $itemData['Body Symmetry'][] = [
                        'type' => 'literal', 
                        'property_id' => 7654,
                        '@value' => $displayValue
                    ];
                }
                
                // Extract base type
                if (isset($rdfData[$morphUri]['https://purl.org/megalod/ms/ah/base'])) {
                    $baseUri = $rdfData[$morphUri]['https://purl.org/megalod/ms/ah/base'][0]['value'];
                    $baseName = basename($baseUri);
                    
                    $itemData['Base Type'][] = [
                        'type' => 'literal',
                        'property_id' => 7655,
                        '@value' => ucfirst($baseName)
                    ];
                }
            }
        }
    }
}

/**
 * Process chipping data and extract all components
 */
private function processChippingData($rdfData, $subject, &$itemData) {
    if (isset($rdfData[$subject]['https://purl.org/megalod/ms/ah/hasChipping'])) {
        foreach ($rdfData[$subject]['https://purl.org/megalod/ms/ah/hasChipping'] as $chipObj) {
            if ($chipObj['type'] === 'uri' && isset($rdfData[$chipObj['value']])) {
                $chipUri = $chipObj['value'];
                
                // Map of chipping properties
                $chippingProps = [
                    'https://purl.org/megalod/ms/ah/chippingMode' => ['Chipping Mode', 7656, 'uri'],
                    'https://purl.org/megalod/ms/ah/chippingAmplitude' => ['Chipping Amplitude', 7657, 'boolean'],
                    'https://purl.org/megalod/ms/ah/chippingDirection' => ['Chipping Direction', 7658, 'uri'],
                    'https://purl.org/megalod/ms/ah/chippingOrientation' => ['Chipping Orientation', 7659, 'boolean'],
                    'https://purl.org/megalod/ms/ah/chippingDelineation' => ['Chipping Delineation', 7660, 'uri'],
                    'https://purl.org/megalod/ms/ah/chippingLocationSide' => ['Chipping Location Side', 7662, 'uri_multiple'],
                    'https://purl.org/megalod/ms/ah/chippingLocationTransversal' => ['Chipping Location Transversal', 7663, 'uri_multiple'],
                    'https://purl.org/megalod/ms/ah/chippingShape' => ['Chipping Shape', 7661, 'uri'],
                ];
                
                foreach ($chippingProps as $predicate => $config) {
                    if (isset($rdfData[$chipUri][$predicate])) {
                        $label = $config[0];
                        $propertyId = $config[1];
                        $dataType = $config[2];
                        
                        if (!isset($itemData[$label])) {
                            $itemData[$label] = [];
                        }
                        
                        if ($dataType === 'uri_multiple') {
                            // Handle multiple values
                            foreach ($rdfData[$chipUri][$predicate] as $valueObj) {
                                $value = $this->formatChippingValue($valueObj, $dataType);
                                if ($value) {
                                    $itemData[$label][] = [
                                        'type' => 'literal',
                                        'property_id' => $propertyId,
                                        '@value' => $value
                                    ];
                                }
                            }
                        } else {
                            // Handle single value
                            $valueObj = $rdfData[$chipUri][$predicate][0];
                            $value = $this->formatChippingValue($valueObj, $dataType);
                            
                            if ($value) {
                                $itemData[$label][] = [
                                    'type' => 'literal',
                                    'property_id' => $propertyId,
                                    '@value' => $value
                                ];
                            }
                        }
                    }
                }
            }
        }
    }
}

/**
 * Process coordinates data - debug version
 */
private function processCoordinatesData($rdfData, $subject, &$itemData) {
    error_log('=== COORDINATE DEBUG START ===', 3, OMEKA_PATH . '/logs/coordinates.log');
    error_log('Subject: ' . $subject, 3, OMEKA_PATH . '/logs/coordinates.log');
    
    if (isset($rdfData[$subject]['https://purl.org/megalod/ms/excavation/hasCoordinatesInSquare'])) {
        error_log('Found hasCoordinatesInSquare property', 3, OMEKA_PATH . '/logs/coordinates.log');
        
        foreach ($rdfData[$subject]['https://purl.org/megalod/ms/excavation/hasCoordinatesInSquare'] as $coordObj) {
            error_log('Coordinate Object: ' . json_encode($coordObj), 3, OMEKA_PATH . '/logs/coordinates.log');
            
            if ($coordObj['type'] === 'uri') {
                $coordUri = $coordObj['value'];
                error_log('Coordinate URI: ' . $coordUri, 3, OMEKA_PATH . '/logs/coordinates.log');
                
                // Check if the coordinate resource exists in RDF data
                if (isset($rdfData[$coordUri])) {
                    error_log('Coordinate resource found in RDF data', 3, OMEKA_PATH . '/logs/coordinates.log');
                    error_log('Coordinate resource properties: ' . json_encode(array_keys($rdfData[$coordUri])), 3, OMEKA_PATH . '/logs/coordinates.log');
                    
                    // Check for schema:value properties
                    if (isset($rdfData[$coordUri]['http://schema.org/value'])) {
                        $values = $rdfData[$coordUri]['http://schema.org/value'];
                        error_log('Found schema:value properties: ' . json_encode($values), 3, OMEKA_PATH . '/logs/coordinates.log');
                        
                        // Rest of your coordinate processing...
                        if (!isset($itemData['Coordinates'])) {
                            $itemData['Coordinates'] = [];
                        }
                        
                        $coordString = '';
                        
                        // X coordinate [0]
                        if (isset($values[0]) && $values[0]['type'] === 'literal') {
                            $coordString .= 'X: ' . $values[0]['value'];
                            error_log('Added X coordinate: ' . $values[0]['value'], 3, OMEKA_PATH . '/logs/coordinates.log');
                        }
                        
                        // Y coordinate [1] 
                        if (isset($values[1]) && $values[1]['type'] === 'literal') {
                            if ($coordString) $coordString .= ', ';
                            $coordString .= 'Y: ' . $values[1]['value'];
                            error_log('Added Y coordinate: ' . $values[1]['value'], 3, OMEKA_PATH . '/logs/coordinates.log');
                        }
                        
                        // Z coordinate [2]
                        if (isset($values[2]) && $values[2]['type'] === 'literal') {
                            if ($coordString) $coordString .= ', ';
                            $coordString .= 'Z: ' . $values[2]['value'];
                            error_log('Added Z coordinate: ' . $values[2]['value'], 3, OMEKA_PATH . '/logs/coordinates.log');
                        }
                        
                        if ($coordString) {
                            $itemData['Coordinates'][] = [
                                'type' => 'literal',
                                'property_id' => 5550,
                                '@value' => $coordString
                            ];
                            error_log('Final coordinate string: ' . $coordString, 3, OMEKA_PATH . '/logs/coordinates.log');
                        } else {
                            error_log('No coordinate string generated', 3, OMEKA_PATH . '/logs/coordinates.log');
                        }
                    } else {
                        error_log('No schema:value properties found in coordinate resource', 3, OMEKA_PATH . '/logs/coordinates.log');
                        error_log('Available properties in coordinate resource: ' . implode(', ', array_keys($rdfData[$coordUri])), 3, OMEKA_PATH . '/logs/coordinates.log');
                    }
                } else {
                    error_log('Coordinate resource NOT found in RDF data', 3, OMEKA_PATH . '/logs/coordinates.log');
                    error_log('Available RDF resources: ' . implode(', ', array_slice(array_keys($rdfData), 0, 10)), 3, OMEKA_PATH . '/logs/coordinates.log');
                }
            }
        }
    } else {
        error_log('No hasCoordinatesInSquare property found', 3, OMEKA_PATH . '/logs/coordinates.log');
        error_log('Available properties for subject: ' . implode(', ', array_keys($rdfData[$subject] ?? [])), 3, OMEKA_PATH . '/logs/coordinates.log');
    }
    
    error_log('=== COORDINATE DEBUG END ===', 3, OMEKA_PATH . '/logs/coordinates.log');
}

/**
 * Format chipping values based on their type
 */
private function formatChippingValue($valueObj, $dataType) {
    if ($dataType === 'boolean') {
        return ($valueObj['value'] === 'true') ? 'Marginal' : 'Deep';
    } elseif ($dataType === 'uri' || $dataType === 'uri_multiple') {
        return ucfirst(basename($valueObj['value']));
    } else {
        return $valueObj['value'];
    }
}




/**
 * Enhanced item finder with alternative search strategies
 */
private function findItemByAlternativeSearch($identifier, $context = '') {
    try {
        // Strategy 1: Direct identifier search
        $response = $this->api()->search('items', [
            'property' => [
                [
                    'property' => 10, // dcterms:identifier property ID
                    'type' => 'eq',
                    'text' => $identifier
                ]
            ],
            'limit' => 1
        ]);
        
        $items = $response->getContent();
        if (!empty($items)) {
            return $items[0];
        }
        
        // Strategy 2: Search by title containing the identifier
        $response = $this->api()->search('items', [
            'property' => [
                [
                    'property' => 1, // dcterms:title property ID
                    'type' => 'in',
                    'text' => $identifier
                ]
            ],
            'limit' => 1
        ]);
        
        $items = $response->getContent();
        if (!empty($items)) {
            return $items[0];
        }
        
        // Strategy 3: Context-specific searches
        if ($context === 'Found in Square') {
            // Look for items with "Square" in the title and the identifier
            $response = $this->api()->search('items', [
                'fulltext_search' => "Square $identifier",
                'limit' => 1
            ]);
            
            $items = $response->getContent();
            if (!empty($items)) {
                return $items[0];
            }
        }
        
        if ($context === 'Found in Context') {
            // Look for items with "Context" in the title and the identifier
            $response = $this->api()->search('items', [
                'fulltext_search' => "Context $identifier",
                'limit' => 1
            ]);
            
            $items = $response->getContent();
            if (!empty($items)) {
                return $items[0];
            }
        }
        
        if ($context === 'Found in SVU') {
            // Look for items with "Stratigraphic" or "SVU" in the title and the identifier
            $searches = ["Stratigraphic $identifier", "SVU $identifier"];
            
            foreach ($searches as $searchTerm) {
                $response = $this->api()->search('items', [
                    'fulltext_search' => $searchTerm,
                    'limit' => 1
                ]);
                
                $items = $response->getContent();
                if (!empty($items)) {
                    return $items[0];
                }
            }
        }
        
        // Strategy 4: Fuzzy search by removing prefixes
        $cleanId = preg_replace('/^[A-Z]+-/', '', $identifier); // Remove prefixes like "CTX-", "SVU-", etc.
        if ($cleanId !== $identifier) {
            $response = $this->api()->search('items', [
                'property' => [
                    [
                        'property' => 10, // dcterms:identifier property ID
                        'type' => 'eq',
                        'text' => $cleanId
                    ]
                ],
                'limit' => 1
            ]);
            
            $items = $response->getContent();
            if (!empty($items)) {
                return $items[0];
            }
        }
        
        error_log("Alternative search strategies failed for identifier '$identifier' in context '$context'", 3, OMEKA_PATH . '/logs/resource-links.log');
        return null;
        
    } catch (\Exception $e) {
        error_log('Error in alternative search: ' . $e->getMessage(), 3, OMEKA_PATH . '/logs/resource-links.log');
        return null;
    }
}

/**
 * Enhanced resource identifier extraction with better error handling
 */
private function extractResourceIdentifier($rdfData, $resourceUri) {
    if (!isset($rdfData[$resourceUri])) {
        error_log("Resource URI not found in RDF data: $resourceUri", 3, OMEKA_PATH . '/logs/resource-links.log');
        return null;
    }
    
    // Try different identifier predicates
    $identifierPredicates = [
        'http://purl.org/dc/terms/identifier',
        'dct:identifier',
        'dcterms:identifier'
    ];
    
    foreach ($identifierPredicates as $predicate) {
        if (isset($rdfData[$resourceUri][$predicate])) {
            foreach ($rdfData[$resourceUri][$predicate] as $idObj) {
                if ($idObj['type'] === 'literal') {
                    error_log("Found identifier '$idObj[value]' for resource $resourceUri using predicate $predicate", 3, OMEKA_PATH . '/logs/resource-links.log');
                    return $idObj['value'];
                }
            }
        }
    }
    
    // Fallback: try to extract from URI structure
    $uriParts = explode('/', $resourceUri);
    $lastPart = end($uriParts);
    
    // If the last part looks like an ID (contains letters and numbers), use it
    if (preg_match('/^[A-Za-z0-9-]+$/', $lastPart) && strlen($lastPart) > 1) {
        error_log("Extracted identifier '$lastPart' from URI structure: $resourceUri", 3, OMEKA_PATH . '/logs/resource-links.log');
        return $lastPart;
    }
    
    error_log("Could not extract identifier from resource: $resourceUri", 3, OMEKA_PATH . '/logs/resource-links.log');
    return null;
}


/**
 * Normalize URIs in TTL data to follow the standard pattern
 * 
 * @param string $ttlData The original TTL data
 * @param int $itemSetId The Omeka S item set ID
 * @return string The modified TTL data with normalized URIs
 */
private function normalizeUris($ttlData, $itemSetId) {
    // Use regex to find all resource URIs in the TTL
    $pattern = '/<https:\/\/purl\.org\/megalod\/example\/([^\/]+)\/([^>]+)>/';
    
    // Map of original URIs to new URIs
    $uriMappings = [];
    
    // Find all URIs and create mappings
    preg_match_all($pattern, $ttlData, $matches, PREG_SET_ORDER);
    
    foreach ($matches as $match) {
        $fullUri = $match[0];
        $resourceType = $match[1]; // e.g., "arrowhead", "morphology", etc.
        $resourceId = $match[2];   // e.g., "CV-AH-001"
        
        // Create new URI that maintains the resource type and ID
        $newUri = "<https://purl.org/megalod/{$itemSetId}/{$resourceType}/{$resourceId}>";
        
        // Store the mapping
        $uriMappings[$fullUri] = $newUri;
    }
    
    // Add declarations for referenced resources
    $declarations = $this->generateResourceDeclarations($uriMappings, $itemSetId);
    
    // Replace all occurrences
    $modifiedTtl = $ttlData;
    foreach ($uriMappings as $oldUri => $newUri) {
        $modifiedTtl = str_replace($oldUri, $newUri, $modifiedTtl);
    }
    
    
    
    error_log('Modified TTL with new URIs: ' . $modifiedTtl, 3, OMEKA_PATH . '/logs/ttl-modification.log');
    return $modifiedTtl;
}

/**
 * Generate declarations for referenced resources
 * 
 * @param array $uriMappings Map of original URIs to new URIs
 * @param int $itemSetId The Omeka S item set ID
 * @return string TTL declarations for referenced resources
 */
private function generateResourceDeclarations($uriMappings, $itemSetId) {
    $declarations = '';
    $declaredResources = [];
    
    // Extract unique resource types and IDs from the mappings
    foreach ($uriMappings as $oldUri => $newUri) {
        // Extract resource type and ID from the old URI
        if (preg_match('/<https:\/\/purl\.org\/megalod\/example\/([^\/]+)\/([^>]+)>/', $oldUri, $match)) {
            $resourceType = $match[1];
            $resourceId = $match[2];
            
            // Skip if we've already declared this resource
            if (isset($declaredResources[$resourceType][$resourceId])) {
                continue;
            }
            
            // Skip controlled vocabulary terms
            if (strpos($oldUri, 'kos') !== false) {
                continue;
            }
            
            // Add declaration based on resource type
            switch ($resourceType) {
                case 'excavation':
                    $declarations .= "{$newUri} a excav:Excavation ;\n    dct:identifier \"{$resourceId}\"^^xsd:literal .\n\n";
                    break;
                    
                case 'context':
                    $declarations .= "{$newUri} a excav:Context ;\n    dct:identifier \"{$resourceId}\"^^xsd:literal .\n\n";
                    break;
                    
                case 'svu':
                    $declarations .= "{$newUri} a excav:StratigraphicVolumeUnit ;\n    dct:identifier \"{$resourceId}\"^^xsd:literal .\n\n";
                    break;
                    
                case 'square':
                    // Extract just the square ID without any prefix
                    $squareId = preg_replace('/^[A-Za-z]+-/', '', $resourceId);
                    $declarations .= "{$newUri} a excav:Square ;\n    dct:identifier \"{$squareId}\"^^xsd:literal .\n\n";
                    break;
            }
            
            // Mark this resource as declared
            $declaredResources[$resourceType][$resourceId] = true;
        }
    }
    
    return $declarations;
}


/**
 * Extract the measurement value from a typometry URI - IMPROVED VERSION
 */
private function extractMeasurementValue($rdfData, $typometryUri) {
    error_log('Extracting measurement value from: ' . $typometryUri, 3, OMEKA_PATH . '/logs/measurements.log');
    
    if (!isset($rdfData[$typometryUri])) {
        error_log('URI not found in RDF data: ' . $typometryUri, 3, OMEKA_PATH . '/logs/measurements.log');
        return null;
    }
    
    // Log all available properties for debugging
    error_log('Available properties: ' . implode(', ', array_keys($rdfData[$typometryUri])), 3, OMEKA_PATH . '/logs/measurements.log');
    
    // Primary: schema:value property
    if (isset($rdfData[$typometryUri]['http://schema.org/value'])) {
        foreach ($rdfData[$typometryUri]['http://schema.org/value'] as $valueObj) {
            if ($valueObj['type'] === 'literal') {
                error_log('Found schema:value: ' . $valueObj['value'], 3, OMEKA_PATH . '/logs/measurements.log');
                return $valueObj['value'];
            }
        }
    }
    
    // Backup strategies for different value properties
    $alternativeValueProps = [
        'http://www.w3.org/1999/02/22-rdf-syntax-ns#value',
        'http://purl.org/dc/terms/extent',
        'http://qudt.org/schema/qudt#numericValue'
    ];
    
    foreach ($alternativeValueProps as $valueProp) {
        if (isset($rdfData[$typometryUri][$valueProp])) {
            foreach ($rdfData[$typometryUri][$valueProp] as $valueObj) {
                if ($valueObj['type'] === 'literal') {
                    error_log('Found alternate value via ' . $valueProp . ': ' . $valueObj['value'], 3, OMEKA_PATH . '/logs/measurements.log');
                    return $valueObj['value'];
                }
            }
        }
    }
    
    error_log('No value property found for: ' . $typometryUri, 3, OMEKA_PATH . '/logs/measurements.log');
    return null;
}

/**
 * Extract the measurement unit from a typometry URI - IMPROVED VERSION
 */
private function extractMeasurementUnit($rdfData, $typometryUri) {
    error_log('Extracting measurement unit from: ' . $typometryUri, 3, OMEKA_PATH . '/logs/measurements.log');
    
    if (!isset($rdfData[$typometryUri])) {
        return null;
    }
    
    // Primary: schema:UnitCode property
    if (isset($rdfData[$typometryUri]['http://schema.org/UnitCode'])) {
        foreach ($rdfData[$typometryUri]['http://schema.org/UnitCode'] as $unitObj) {
            if ($unitObj['type'] === 'literal') {
                error_log('Found literal unit: ' . $unitObj['value'], 3, OMEKA_PATH . '/logs/measurements.log');
                return $unitObj['value'];
            } elseif ($unitObj['type'] === 'uri') {
                $parts = explode('/', $unitObj['value']);
                $unit = end($parts);
                error_log('Found URI unit: ' . $unit, 3, OMEKA_PATH . '/logs/measurements.log');
                return $unit;
            }
        }
    }
    
    // Alternative unit properties
    $alternativeUnitProps = [
        'http://schema.org/unitCode',
        'http://purl.org/dc/terms/format',
        'http://qudt.org/schema/qudt#unit',
        'http://qudt.org/schema/qudt#hasUnit'
    ];
    
    foreach ($alternativeUnitProps as $unitProp) {
        if (isset($rdfData[$typometryUri][$unitProp])) {
            foreach ($rdfData[$typometryUri][$unitProp] as $unitObj) {
                if ($unitObj['type'] === 'literal') {
                    error_log('Found alternate literal unit via ' . $unitProp . ': ' . $unitObj['value'], 3, OMEKA_PATH . '/logs/measurements.log');
                    return $unitObj['value'];
                } elseif ($unitObj['type'] === 'uri') {
                    $parts = explode('/', $unitObj['value']);
                    $unit = end($parts);
                    error_log('Found alternate URI unit via ' . $unitProp . ': ' . $unit, 3, OMEKA_PATH . '/logs/measurements.log');
                    return $unit;
                }
            }
        }
    }
    
    // Infer unit from resource type for Weight resources
    if (isset($rdfData[$typometryUri]['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'])) {
        foreach ($rdfData[$typometryUri]['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'] as $typeObj) {
            if ($typeObj['value'] === 'https://purl.org/megalod/ms/excavation/Weight') {
                // return whatever unit was used in the value
                $value = $this->extractMeasurementValue($rdfData, $typometryUri);
                if ($value !== null) {
                    // Check if the value contains a unit
                    if (preg_match('/\s([a-zA-Z]+)/', $value, $matches)) {
                        $unit = $matches[1];
                        error_log('Inferred unit from value: ' . $unit, 3, OMEKA_PATH . '/logs/measurements.log');
                        return $unit;
                    } else {
                        error_log('No unit found in value: ' . $value, 3, OMEKA_PATH . '/logs/measurements.log');
                        return null; // No unit found
                    }
                }
            }
        }
    }
    
    error_log('No unit found for: ' . $typometryUri, 3, OMEKA_PATH . '/logs/measurements.log');
    return null;
}


/**
 * Enhanced findItemByIdentifier with comprehensive search strategies
 */
private function findItemByIdentifier($identifier) {
    try {
        error_log("Searching for item with identifier: '$identifier'", 3, OMEKA_PATH . '/logs/resource-links.log');
        
        // Strategy 1: Direct identifier search
        $response = $this->api()->search('items', [
            'property' => [
                [
                    'property' => 10, // dcterms:identifier property ID
                    'type' => 'eq',
                    'text' => $identifier
                ]
            ],
            'limit' => 1
        ]);
        
        $items = $response->getContent();
        if (!empty($items)) {
            error_log("Found item with identifier '$identifier': ID " . $items[0]->id(), 3, OMEKA_PATH . '/logs/resource-links.log');
            return $items[0];
        }
        
        // Strategy 2: Search by title containing the identifier
        $response = $this->api()->search('items', [
            'property' => [
                [
                    'property' => 1, // dcterms:title property ID
                    'type' => 'in',
                    'text' => $identifier
                ]
            ],
            'limit' => 1
        ]);
        
        $items = $response->getContent();
        if (!empty($items)) {
            error_log("Found item by title containing '$identifier': ID " . $items[0]->id(), 3, OMEKA_PATH . '/logs/resource-links.log');
            return $items[0];
        }
        
        // Strategy 3: Full text search
        $response = $this->api()->search('items', [
            'fulltext_search' => $identifier,
            'limit' => 5 // Get a few results to find the best match
        ]);
        
        $items = $response->getContent();
        if (!empty($items)) {
            // Try to find the best match
            foreach ($items as $item) {
                $title = $item->displayTitle();
                $values = $item->values();
                
                // Check if the title contains our identifier
                if (stripos($title, $identifier) !== false) {
                    error_log("Found item by fulltext search matching '$identifier': ID " . $item->id(), 3, OMEKA_PATH . '/logs/resource-links.log');
                    return $item;
                }
                
                // Check if any property contains our identifier
                foreach ($values as $propertyValues) {
                    if (!empty($propertyValues)) {
                        foreach ($propertyValues as $value) {
                            if ($value instanceof \Omeka\Api\Representation\ValueRepresentation && stripos($value->value(), $identifier) !== false) {
                                error_log("Found item by property value matching '$identifier': ID " . $item->id(), 3, OMEKA_PATH . '/logs/resource-links.log');
                                return $item;
                            }
                        }
                    }
                }
            }
            
            // If no exact match, return the first result
            error_log("Using first fulltext search result for '$identifier': ID " . $items[0]->id(), 3, OMEKA_PATH . '/logs/resource-links.log');
            return $items[0];
        }
        
        // Strategy 4: Search item sets (in case it's an excavation reference)
        $itemSetResponse = $this->api()->search('item_sets', [
            'property' => [
                [
                    'property' => 10, // dcterms:identifier property ID
                    'type' => 'eq', 
                    'text' => $identifier
                ]
            ],
            'limit' => 1
        ]);
        
        $itemSets = $itemSetResponse->getContent();
        if (!empty($itemSets)) {
            error_log("Found item set with identifier '$identifier': ID " . $itemSets[0]->id(), 3, OMEKA_PATH . '/logs/resource-links.log');
            return $itemSets[0];
        }
        
        // Strategy 5: Try removing common prefixes and searching again
        $prefixesToRemove = ['CV-', 'EXC-', 'CTX-', 'SVU-', 'AH-', 'SU-'];
        foreach ($prefixesToRemove as $prefix) {
            if (strpos($identifier, $prefix) === 0) {
                $cleanIdentifier = substr($identifier, strlen($prefix));
                error_log("Trying clean identifier: '$cleanIdentifier' (removed '$prefix')", 3, OMEKA_PATH . '/logs/resource-links.log');
                
                $response = $this->api()->search('items', [
                    'property' => [
                        [
                            'property' => 10, // dcterms:identifier property ID
                            'type' => 'eq',
                            'text' => $cleanIdentifier
                        ]
                    ],
                    'limit' => 1
                ]);
                
                $items = $response->getContent();
                if (!empty($items)) {
                    error_log("Found item with clean identifier '$cleanIdentifier': ID " . $items[0]->id(), 3, OMEKA_PATH . '/logs/resource-links.log');
                    return $items[0];
                }
            }
        }
        
        // Strategy 6: Try pattern matching for common formats
        // Handle patterns like "A1", "B2" (squares), "001", "002" (contexts/SVUs)
        if (preg_match('/^([A-Z])(\d+)$/', $identifier, $matches)) {
            // This looks like a square identifier (A1, B2, etc.)
            $searchTerms = [
                "Square $identifier",
                "Square " . $matches[1] . "-" . $matches[2],
                $matches[1] . $matches[2]
            ];
            
            foreach ($searchTerms as $searchTerm) {
                $response = $this->api()->search('items', [
                    'fulltext_search' => $searchTerm,
                    'limit' => 1
                ]);
                
                $items = $response->getContent();
                if (!empty($items)) {
                    error_log("Found item with square pattern '$searchTerm': ID " . $items[0]->id(), 3, OMEKA_PATH . '/logs/resource-links.log');
                    return $items[0];
                }
            }
        }
        
        error_log("No item or item set found with identifier '$identifier'", 3, OMEKA_PATH . '/logs/resource-links.log');
        return null;
        
    } catch (\Exception $e) {
        error_log('Error finding item by identifier: ' . $e->getMessage(), 3, OMEKA_PATH . '/logs/resource-links.log');
        return null;
    }
}
/**
 * Find or create an archaeologist item
 * This prevents duplicates by using ORCID or name as unique identifiers
 */
private function findOrCreateArchaeologist($archaeologistData) {
    $orcid = $archaeologistData['orcid'] ?? null;
    $name = $archaeologistData['name'] ?? null;
    $email = $archaeologistData['email'] ?? null;
    
    if (!$name && !$orcid) {
        return null; // No archaeologist data to work with
    }
    
    // First, try to find existing archaeologist by ORCID (most reliable)
    if ($orcid) {
        try {
            $response = $this->api()->search('items', [
                'resource_class_id' => 94, // Person class (adjust ID as needed)
                'property' => [
                    [
                        'property' => 176, // foaf:account property ID (adjust as needed)
                        'type' => 'eq',
                        'text' => $orcid
                    ]
                ],
                'limit' => 1
            ]);
            
            $items = $response->getContent();
            if (!empty($items)) {
                error_log("Found existing archaeologist by ORCID: {$items[0]->id()}", 3, OMEKA_PATH . '/logs/archaeologist.log');
                return $items[0];
            }
        } catch (\Exception $e) {
            error_log('Error searching for archaeologist by ORCID: ' . $e->getMessage(), 3, OMEKA_PATH . '/logs/archaeologist.log');
        }
    }
    
    // If not found by ORCID, try by name
    if ($name) {
        try {
            $response = $this->api()->search('items', [
                'resource_class_id' => 94, // Person class (adjust ID as needed)
                'property' => [
                    [
                        'property' => 8, // foaf:name property ID (adjust as needed)
                        'type' => 'eq',
                        'text' => $name
                    ]
                ],
                'limit' => 1
            ]);
            
            $items = $response->getContent();
            if (!empty($items)) {
                error_log("Found existing archaeologist by name: {$items[0]->id()}", 3, OMEKA_PATH . '/logs/archaeologist.log');
                return $items[0];
            }
        } catch (\Exception $e) {
            error_log('Error searching for archaeologist by name: ' . $e->getMessage(), 3, OMEKA_PATH . '/logs/archaeologist.log');
        }
    }
    
    // If not found, create new archaeologist item
    try {
        $itemData = [
            'o:resource_class' => ['o:id' => 94], // Person class (adjust ID as needed)
            'dcterms:title' => [
                [
                    'type' => 'literal',
                    'property_id' => 1,
                    '@value' => $name ?: 'Archaeologist ' . ($orcid ?: 'Unknown')
                ]
            ],
            'o:is_public' => true
        ];
        
        // Add name if available
        if ($name) {
            $itemData['foaf:name'] = [
                [
                    'type' => 'literal',
                    'property_id' => 8, // foaf:name property ID
                    '@value' => $name
                ]
            ];
        }
        
        // Add ORCID if available
        if ($orcid) {
            $itemData['foaf:account'] = [
                [
                    'type' => 'uri',
                    'property_id' => 176, // foaf:account property ID
                    '@id' => "https://orcid.org/$orcid",
                    'o:label' => "ORCID: $orcid"
                ]
            ];
        }
        
        // Add email if available
        if ($email) {
            $itemData['foaf:mbox'] = [
                [
                    'type' => 'uri',
                    'property_id' => 123, // foaf:mbox property ID
                    '@id' => "mailto:$email",
                    'o:label' => $email
                ]
            ];
        }
        
        // Add archaeologist-specific type
        $itemData['dcterms:type'] = [
            [
                'type' => 'literal',
                'property_id' => 8,
                '@value' => 'Archaeologist'
            ]
        ];
        
        $response = $this->api()->create('items', $itemData);
        if ($response) {
            $newItem = $response->getContent();
            error_log("Created new archaeologist item: {$newItem->id()} ($name)", 3, OMEKA_PATH . '/logs/archaeologist.log');
            return $newItem;
        }
        
    } catch (\Exception $e) {
        error_log('Error creating archaeologist item: ' . $e->getMessage(), 3, OMEKA_PATH . '/logs/archaeologist.log');
    }
    
    return null;
}


/**
 * Process excavation specific data
 */
private function processExcavationData($rdfData, $subject, &$itemData) {
    // Extract location name for description
    if (isset($rdfData[$subject]['http://www.ontologydesignpatterns.org/ont/dul/DUL.owl#hasLocation'])) {
        foreach ($rdfData[$subject]['http://www.ontologydesignpatterns.org/ont/dul/DUL.owl#hasLocation'] as $locObj) {
            if ($locObj['type'] === 'uri' && isset($rdfData[$locObj['value']])) {
                $locationUri = $locObj['value'];
                $locationName = $this->extractLocationName($rdfData, $locationUri);
                
                if ($locationName) {
                    // Create description
                    if (!isset($itemData['dcterms:description'])) {
                        $itemData['dcterms:description'] = [];
                    }
                    
                    $itemData['dcterms:description'][] = [
                        'type' => 'literal',
                        'property_id' => 4, // dcterms:description property ID
                        '@value' => "Archaeological excavation at $locationName"
                    ];
                }
            }
        }
    }

    // Add links to contexts with resource linking
    if (isset($rdfData[$subject]['https://purl.org/megalod/ms/excavation/hasContext'])) {
        foreach ($rdfData[$subject]['https://purl.org/megalod/ms/excavation/hasContext'] as $contextObj) {
            if ($contextObj['type'] === 'uri') {
                $contextId = $this->extractResourceIdentifier($rdfData, $contextObj['value']);
                if ($contextId) {
                    // Find the actual Omeka item with this identifier
                    $linkedItem = $this->findItemByIdentifier($contextId);
                    if ($linkedItem) {
                        if (!isset($itemData['Excavation - hasContext'])) {
                            $itemData['Excavation - hasContext'] = [];
                        }
                        
                        $itemData['Excavation - hasContext'][] = [
                            'type' => 'resource',
                            'property_id' => 7666, // Use appropriate property ID
                            'value_resource_id' => $linkedItem->id(),
                            'o:label' => $contextId
                        ];
                    } else {
                        // Fallback to literal if no linked item found
                        if (!isset($itemData['Excavation - hasContext'])) {
                            $itemData['Excavation - hasContext'] = [];
                        }
                        
                        $itemData['Excavation - hasContext'][] = [
                            'type' => 'literal',
                            'property_id' => 7666,
                            '@value' => $contextId
                        ];
                    }
                }
            }
        }
    }
    
    // Add links to squares with resource linking
    if (isset($rdfData[$subject]['https://purl.org/megalod/ms/excavation/hasSquare'])) {
        foreach ($rdfData[$subject]['https://purl.org/megalod/ms/excavation/hasSquare'] as $squareObj) {
            if ($squareObj['type'] === 'uri') {
                $squareId = $this->extractResourceIdentifier($rdfData, $squareObj['value']);
                if ($squareId) {
                    // Find the actual Omeka item with this identifier
                    $linkedItem = $this->findItemByIdentifier($squareId);
                    if ($linkedItem) {
                        if (!isset($itemData['The Square'])) {
                            $itemData['The Square'] = [];
                        }
                        
                        $itemData['The Square'][] = [
                            'type' => 'resource',
                            'property_id' => 7668, // Use appropriate property ID
                            'value_resource_id' => $linkedItem->id(),
                            'o:label' => $squareId
                        ];
                    } else {
                        // Fallback to literal if no linked item found
                        if (!isset($itemData['The Square'])) {
                            $itemData['The Square'] = [];
                        }
                        
                        $itemData['The Square'][] = [
                            'type' => 'literal',
                            'property_id' => 7668,
                            '@value' => $squareId
                        ];
                    }
                }
            }
        }
    }

    // NEW: Add links to StratigraphicVolumeUnits with resource linking
    if (isset($rdfData[$subject]['https://purl.org/megalod/ms/excavation/hasSVU'])) {
        foreach ($rdfData[$subject]['https://purl.org/megalod/ms/excavation/hasSVU'] as $svuObj) {
            if ($svuObj['type'] === 'uri') {
                $svuId = $this->extractResourceIdentifier($rdfData, $svuObj['value']);
                if ($svuId) {
                    // Find the actual Omeka item with this identifier
                    $linkedItem = $this->findItemByIdentifier($svuId);
                    if ($linkedItem) {
                        if (!isset($itemData['Stratigraphic Volume Unit'])) {
                            $itemData['Stratigraphic Volume Unit'] = [];
                        }
                        
                        $itemData['Stratigraphic Volume Unit'][] = [
                            'type' => 'resource',
                            'property_id' => 7667, // Use appropriate property ID for SVU
                            'value_resource_id' => $linkedItem->id(),
                            'o:label' => $svuId
                        ];
                    } else {
                        // Fallback to literal if no linked item found
                        if (!isset($itemData['Stratigraphic Volume Unit'])) {
                            $itemData['Stratigraphic Volume Unit'] = [];
                        }
                        
                        $itemData['Stratigraphic Volume Unit'][] = [
                            'type' => 'literal',
                            'property_id' => 7667,
                            '@value' => $svuId
                        ];
                    }
                }
            }
        }
    }

    // Extract location GPS coordinates
    if (isset($rdfData[$subject]['http://www.ontologydesignpatterns.org/ont/dul/DUL.owl#hasLocation'])) {
        foreach ($rdfData[$subject]['http://www.ontologydesignpatterns.org/ont/dul/DUL.owl#hasLocation'] as $locObj) {
            if ($locObj['type'] === 'uri' && isset($rdfData[$locObj['value']])) {
                $locationUri = $locObj['value'];
                
                // Check if location has GPS coordinates
                if (isset($rdfData[$locationUri]['https://purl.org/megalod/ms/excavation/hasGPSCoordinates'])) {
                    foreach ($rdfData[$locationUri]['https://purl.org/megalod/ms/excavation/hasGPSCoordinates'] as $gpsObj) {
                        if ($gpsObj['type'] === 'uri' && isset($rdfData[$gpsObj['value']])) {
                            $gpsUri = $gpsObj['value'];
                            
                            // Extract latitude and longitude
                            $lat = null;
                            $long = null;
                            
                            if (isset($rdfData[$gpsUri]['http://www.w3.org/2003/01/geo/wgs84_pos#lat'])) {
                                foreach ($rdfData[$gpsUri]['http://www.w3.org/2003/01/geo/wgs84_pos#lat'] as $latObj) {
                                    if ($latObj['type'] === 'literal') {
                                        $lat = $latObj['value'];
                                    }
                                }
                            }
                            
                            if (isset($rdfData[$gpsUri]['http://www.w3.org/2003/01/geo/wgs84_pos#long'])) {
                                foreach ($rdfData[$gpsUri]['http://www.w3.org/2003/01/geo/wgs84_pos#long'] as $longObj) {
                                    if ($longObj['type'] === 'literal') {
                                        $long = $longObj['value'];
                                    }
                                }
                            }
                            
                            // Add GPS coordinates as a single field
                            if ($lat && $long) {
                                if (!isset($itemData['GPS Coordinates'])) {
                                    $itemData['GPS Coordinates'] = [];
                                }
                                
                                $itemData['GPS Coordinates'][] = [
                                    'type' => 'literal',
                                    'property_id' => 7664, // Use appropriate property ID
                                    '@value' => "Latitude: $lat, Longitude: $long"
                                ];
                            }
                        }
                    }
                }
                
                // District
                if (isset($rdfData[$locationUri]['http://dbpedia.org/ontology/District'])) {
                    foreach ($rdfData[$locationUri]['http://dbpedia.org/ontology/District'] as $distObj) {
                        if ($distObj['type'] === 'uri') {
                            $distUri = $distObj['value'];
                            if (isset($rdfData[$distUri])) {
                                $parts = explode('/', $distUri);
                                $districtName = end($parts);
                                
                                // Add District as a separate field
                                if (!isset($itemData['District'])) {
                                    $itemData['District'] = [];
                                }
                                
                                $itemData['District'][] = [
                                    'type' => 'literal',
                                    'property_id' => 1555, // Use an appropriate property ID
                                    '@value' => $districtName
                                ];
                            }
                        }
                    }
                }

                // Parish - similar modification
                if (isset($rdfData[$locationUri]['http://dbpedia.org/ontology/Parish'])) {
                    foreach ($rdfData[$locationUri]['http://dbpedia.org/ontology/Parish'] as $parishObj) {
                        if ($parishObj['type'] === 'uri') {
                            $parishUri = $parishObj['value'];
                            if (isset($rdfData[$parishUri])) {
                                $parts = explode('/', $parishUri);
                                $parishName = end($parts);
                                
                                // Add Parish as a separate field
                                if (!isset($itemData['Parish'])) {
                                    $itemData['Parish'] = [];
                                }
                                
                                $itemData['Parish'][] = [
                                    'type' => 'literal',
                                    'property_id' => 1681, // Use an appropriate property ID
                                    '@value' => $parishName
                                ];
                            }
                        }
                    }
                }

                // Country - similar modification
                if (isset($rdfData[$locationUri]['http://dbpedia.org/ontology/Country'])) {
                    foreach ($rdfData[$locationUri]['http://dbpedia.org/ontology/Country'] as $countryObj) {
                        if ($countryObj['type'] === 'uri') {
                            $countryUri = $countryObj['value'];
                            if (isset($rdfData[$countryUri])) {
                                $parts = explode('/', $countryUri);
                                $countryName = end($parts);
                                
                                // Add Country as a separate field
                                if (!isset($itemData['Country'])) {
                                    $itemData['Country'] = [];
                                }
                                
                                $itemData['Country'][] = [
                                    'type' => 'literal',
                                    'property_id' => 1402, // Use an appropriate property ID
                                    '@value' => $countryName
                                ];
                            }
                        }
                    }
                }
            }
        }
    }
    if (isset($rdfData[$subject]['https://purl.org/megalod/ms/excavation/hasPersonInCharge'])) {
        foreach ($rdfData[$subject]['https://purl.org/megalod/ms/excavation/hasPersonInCharge'] as $archaeologistObj) {
            if ($archaeologistObj['type'] === 'uri' && isset($rdfData[$archaeologistObj['value']])) {
                $archaeologistUri = $archaeologistObj['value'];
                
                // Extract archaeologist data
                $archaeologistData = $this->extractArchaeologistData($rdfData, $archaeologistUri);
                
                if ($archaeologistData) {
                    // Find or create archaeologist item
                    $archaeologistItem = $this->findOrCreateArchaeologist($archaeologistData);
                    
                    if ($archaeologistItem) {
                        if (!isset($itemData['Person in Charge'])) {
                            $itemData['Person in Charge'] = [];
                        }
                        
                        $itemData['Person in Charge'][] = [
                            'type' => 'resource',
                            'property_id' => 7665, // Use appropriate property ID
                            'value_resource_id' => $archaeologistItem->id(),
                            'o:label' => $archaeologistData['name'] ?: $archaeologistData['orcid']
                        ];
                        
                        error_log("Linked archaeologist {$archaeologistItem->id()} to excavation", 3, OMEKA_PATH . '/logs/archaeologist.log');
                    } else {
                        // Fallback to literal
                        if (!isset($itemData['Person in Charge'])) {
                            $itemData['Person in Charge'] = [];
                        }
                        
                        $itemData['Person in Charge'][] = [
                            'type' => 'literal',
                            'property_id' => 7665,
                            '@value' => $archaeologistData['name'] ?: $archaeologistData['orcid']
                        ];
                    }
                }
            }
        }
    }
    
}


/**
 * Process square specific data
 */
private function processSquareData($rdfData, $subject, &$itemData) {
    // Basic properties - direct mapping
    $propertyMap = [
        'http://purl.org/dc/terms/identifier' => ['Square ID', 10],
        'http://www.w3.org/2003/01/geo/wgs84_pos#long' => ['North-South Quota', 259],
        'http://www.w3.org/2003/01/geo/wgs84_pos#lat' => ['East-West Quota', 257]
    ];
    
    // Extract basic properties
    foreach ($propertyMap as $predicate => $mapping) {
        if (isset($rdfData[$subject][$predicate])) {
            $term = $mapping[0];
            $propertyId = $mapping[1];
            
            if (!isset($itemData[$term])) {
                $itemData[$term] = [];
            }
            
            foreach ($rdfData[$subject][$predicate] as $object) {
                if ($object['type'] === 'literal') {
                    $itemData[$term][] = [
                        'type' => 'literal',
                        'property_id' => $propertyId,
                        '@value' => $object['value']
                    ];
                }
            }
        }
    }
    
    if (isset($rdfData[$subject]['https://purl.org/megalod/ms/excavation/foundInExcavation'])) {
        foreach ($rdfData[$subject]['https://purl.org/megalod/ms/excavation/foundInExcavation'] as $excObj) {
            if ($excObj['type'] === 'uri') {
                if (!isset($itemData['The Encounter Event - an item found in an Excavation'])) {
                    $itemData['The Encounter Event - an item found in an Excavation'] = [];
                }
                
                $itemData['The Encounter Event - an item found in an Excavation'][] = [
                    'type' => 'uri',
                    'property_id' => 7673, // Use the appropriate property ID
                    '@id' => $excObj['value'],
                    '@value' => $excObj['value'] // This will make the URL visible in the metadata
                ];
            }
        }
    }
    
    // Add context reference if available
    if (isset($rdfData[$subject]['https://purl.org/megalod/ms/excavation/foundInContext'])) {
        foreach ($rdfData[$subject]['https://purl.org/megalod/ms/excavation/foundInContext'] as $ctxObj) {
            if ($ctxObj['type'] === 'uri') {
                if (!isset($itemData['The Encounter Event - an item found in a specific Context'])) {
                    $itemData['The Encounter Event - an item found in a specific Context'] = [];
                }
                
                $itemData['The Encounter Event - an item found in a specific Context'][] = [
                    'type' => 'uri',
                    'property_id' => 7672, // Use the appropriate property ID
                    '@id' => $ctxObj['value'],
                    '@value' => $ctxObj['value'] // This will make the URL visible in the metadata
                ];
            }
        }
    }
    
    // Add SVU reference if available
    if (isset($rdfData[$subject]['https://purl.org/megalod/ms/excavation/foundInSVU'])) {
        foreach ($rdfData[$subject]['https://purl.org/megalod/ms/excavation/foundInSVU'] as $svuObj) {
            if ($svuObj['type'] === 'uri') {
                if (!isset($itemData['Stratigraphic Unit'])) {
                    $itemData['Stratigraphic Unit'] = [];
                }
                
                $itemData['Stratigraphic Unit'][] = [
                    'type' => 'uri',
                    'property_id' => 7671, // Use the appropriate property ID 
                    '@id' => $svuObj['value'],
                    '@value' => $svuObj['value'] // This will make the URL visible in the metadata
                ];
            }
        }
    }
    
    // Add square reference if available
    if (isset($rdfData[$subject]['https://purl.org/megalod/ms/excavation/foundInSquare'])) {
        foreach ($rdfData[$subject]['https://purl.org/megalod/ms/excavation/foundInSquare'] as $squareObj) {
            if ($squareObj['type'] === 'uri') {
                if (!isset($itemData['Excavation Square'])) {
                    $itemData['Excavation Square'] = [];
                }
                
                $itemData['Excavation Square'][] = [
                    'type' => 'uri',
                    'property_id' => 7668, // Use the appropriate property ID
                    '@id' => $squareObj['value'],
                    '@value' => $squareObj['value'] // This will make the URL visible in the metadata
                ];
            }
        }
    }
}

/**
 * Helper method to extract archaeologist data from RDF
 */
private function extractArchaeologistData($rdfData, $archaeologistUri) {
    $data = [
        'name' => null,
        'orcid' => null,
        'email' => null
    ];
    
    // Extract name
    if (isset($rdfData[$archaeologistUri]['http://xmlns.com/foaf/0.1/name'])) {
        foreach ($rdfData[$archaeologistUri]['http://xmlns.com/foaf/0.1/name'] as $nameObj) {
            if ($nameObj['type'] === 'literal') {
                $data['name'] = $nameObj['value'];
                break;
            }
        }
    }
    
    // Extract ORCID
    if (isset($rdfData[$archaeologistUri]['http://xmlns.com/foaf/0.1/account'])) {
        foreach ($rdfData[$archaeologistUri]['http://xmlns.com/foaf/0.1/account'] as $accountObj) {
            if ($accountObj['type'] === 'uri') {
                $orcidUrl = $accountObj['value'];
                if (strpos($orcidUrl, 'orcid.org') !== false) {
                    $parts = explode('/', $orcidUrl);
                    $data['orcid'] = end($parts);
                    break;
                }
            }
        }
    }
    
    // Extract email
    if (isset($rdfData[$archaeologistUri]['http://xmlns.com/foaf/0.1/mbox'])) {
        foreach ($rdfData[$archaeologistUri]['http://xmlns.com/foaf/0.1/mbox'] as $emailObj) {
            if ($emailObj['type'] === 'uri') {
                $emailUrl = $emailObj['value'];
                if (strpos($emailUrl, 'mailto:') === 0) {
                    $data['email'] = substr($emailUrl, 7);
                    break;
                }
            }
        }
    }
    
    return ($data['name'] || $data['orcid']) ? $data : null;
}

/**
 * Extract location name from URI
 */
private function extractLocationName($rdfData, $locationUri) {
    if (isset($rdfData[$locationUri]['http://dbpedia.org/ontology/informationName'])) {
        foreach ($rdfData[$locationUri]['http://dbpedia.org/ontology/informationName'] as $nameObj) {
            if ($nameObj['type'] === 'literal') {
                return $nameObj['value'];
            }
        }
    }
    return null;
}

/**
 * Process context specific data
 */
private function processContextData($rdfData, $subject, &$itemData) {
    // Basic properties - direct mapping
    $propertyMap = [
        'http://purl.org/dc/terms/identifier' => ['Context ID', 10],
    ];
    
    // Extract basic properties
    foreach ($propertyMap as $predicate => $mapping) {
        if (isset($rdfData[$subject][$predicate])) {
            $term = $mapping[0];
            $propertyId = $mapping[1];
            
            if (!isset($itemData[$term])) {
                $itemData[$term] = [];
            }
            
            foreach ($rdfData[$subject][$predicate] as $object) {
                if ($object['type'] === 'literal') {
                    $itemData[$term][] = [
                        'type' => 'literal',
                        'property_id' => $propertyId,
                        '@value' => $object['value']
                    ];
                }
            }
        }
    }
    
    // NEW: Process SVU relationships with resource linking
    if (isset($rdfData[$subject]['https://purl.org/megalod/ms/excavation/hasSVU'])) {
        foreach ($rdfData[$subject]['https://purl.org/megalod/ms/excavation/hasSVU'] as $svuObj) {
            if ($svuObj['type'] === 'uri') {
                $svuId = $this->extractResourceIdentifier($rdfData, $svuObj['value']);
                if ($svuId) {
                    // Find the actual Omeka item with this identifier
                    $linkedItem = $this->findItemByIdentifier($svuId);
                    if ($linkedItem) {
                        if (!isset($itemData['Stratigraphic Units'])) {
                            $itemData['Stratigraphic Units'] = [];
                        }
                        
                        $itemData['Stratigraphic Units'][] = [
                            'type' => 'resource',
                            'property_id' => 7667, // Use appropriate property ID for SVU
                            'value_resource_id' => $linkedItem->id(),
                            'o:label' => $svuId
                        ];
                    } else {
                        // Fallback to literal if no linked item found
                        if (!isset($itemData['Stratigraphic Units'])) {
                            $itemData['Stratigraphic Units'] = [];
                        }
                        
                        $itemData['Stratigraphic Units'][] = [
                            'type' => 'literal',
                            'property_id' => 7667,
                            '@value' => $svuId
                        ];
                    }
                }
            }
        }
    }
    
    // Check for description
    if (isset($rdfData[$subject]['http://purl.org/dc/terms/description'])) {
        foreach ($rdfData[$subject]['http://purl.org/dc/terms/description'] as $descObj) {
            if ($descObj['type'] === 'literal') {
                if (!isset($itemData['Description'])) {
                    $itemData['Description'] = [];
                }
                
                $itemData['Description'][] = [
                    'type' => 'literal',
                    'property_id' => 4, // Description property ID
                    '@value' => $descObj['value']
                ];
            }
        }
    }
    
    // Extract SVU summaries for better context understanding (keep as additional literal info)
    if (isset($rdfData[$subject]['https://purl.org/megalod/ms/excavation/hasSVU'])) {
        $svuSummaries = [];
        
        foreach ($rdfData[$subject]['https://purl.org/megalod/ms/excavation/hasSVU'] as $svuObj) {
            if ($svuObj['type'] === 'uri' && isset($rdfData[$svuObj['value']])) {
                $svuUri = $svuObj['value'];
                $svuId = $this->extractSVUIdentifier($rdfData, $svuUri);
                $svuDesc = $this->extractSVUDescription($rdfData, $svuUri);
                
                if ($svuId && $svuDesc) {
                    $svuSummaries[] = "$svuId: $svuDesc";
                }
            }
        }
        
        if (!empty($svuSummaries)) {
            if (!isset($itemData['Stratigraphic Unit Summaries'])) {
                $itemData['Stratigraphic Unit Summaries'] = [];
            }
            
            $itemData['Stratigraphic Unit Summaries'][] = [
                'type' => 'literal',
                'property_id' => 19, 
                '@value' => implode(" | ", $svuSummaries)
            ];
        }
    }
}

/**
 * Process SVU (Stratigraphic Volume Unit) specific data
 */
private function processSVUData($rdfData, $subject, &$itemData) {
    // Basic properties - direct mapping
    $propertyMap = [
        'http://purl.org/dc/terms/identifier' => ['SVU ID', 10],
        'http://purl.org/dc/terms/description' => ['Description', 4],
        'https://purl.org/megalod/ms/excavation/hasTimeline' => ['Timeline', 7669]
    ];
    
    // Extract basic properties
    foreach ($propertyMap as $predicate => $mapping) {
        if (isset($rdfData[$subject][$predicate])) {
            $term = $mapping[0];
            $propertyId = $mapping[1];
            
            if (!isset($itemData[$term])) {
                $itemData[$term] = [];
            }
            
            foreach ($rdfData[$subject][$predicate] as $object) {
                if ($object['type'] === 'uri') {
                    // For timeline references, try to extract meaningful time range
                    if ($predicate === 'https://purl.org/megalod/ms/excavation/hasTimeline') {
                        $timelineUri = $object['value'];
                        $timeRange = $this->extractTimelineRange($rdfData, $timelineUri);
                        
                        if ($timeRange) {
                            $itemData[$term][] = [
                                'type' => 'literal',
                                'property_id' => $propertyId,
                                '@value' => $timeRange
                            ];
                        } else {
                            // Fall back to URI ID if time range not found
                            $parts = explode('/', $object['value']);
                            $value = end($parts);
                            
                            $itemData[$term][] = [
                                'type' => 'literal',
                                'property_id' => $propertyId,
                                '@value' => $value
                            ];
                        }
                    } else {
                        // For other URI properties, extract the ID part
                        $parts = explode('/', $object['value']);
                        $value = end($parts);
                        
                        $itemData[$term][] = [
                            'type' => 'literal',
                            'property_id' => $propertyId,
                            '@value' => $value
                        ];
                    }
                } else if ($object['type'] === 'literal') {
                    $itemData[$term][] = [
                        'type' => 'literal',
                        'property_id' => $propertyId,
                        '@value' => $object['value']
                    ];
                }
            }
        }
    }
    
    // Extract timeline details
    if (isset($rdfData[$subject]['https://purl.org/megalod/ms/excavation/hasTimeline'])) {
        foreach ($rdfData[$subject]['https://purl.org/megalod/ms/excavation/hasTimeline'] as $timelineObj) {
            if ($timelineObj['type'] === 'uri' && isset($rdfData[$timelineObj['value']])) {
                $timelineUri = $timelineObj['value'];
                
                // Extract beginning and end points
                $beginningYear = null;
                $beginningBC = null;
                $endYear = null;
                $endBC = null;
                
                // Extract beginning
                if (isset($rdfData[$timelineUri]['http://www.w3.org/2006/time#hasBeginning'])) {
                    foreach ($rdfData[$timelineUri]['http://www.w3.org/2006/time#hasBeginning'] as $beginObj) {
                        if ($beginObj['type'] === 'uri' && isset($rdfData[$beginObj['value']])) {
                            $beginUri = $beginObj['value'];
                            
                            // Extract year
                            if (isset($rdfData[$beginUri]['http://www.w3.org/2006/time#inXSDgYear'])) {
                                foreach ($rdfData[$beginUri]['http://www.w3.org/2006/time#inXSDgYear'] as $yearObj) {
                                    if ($yearObj['type'] === 'literal') {
                                        $beginningYear = $yearObj['value'];
                                    }
                                }
                            }
                            
                            // Extract BC/AC
                            if (isset($rdfData[$beginUri]['https://purl.org/megalod/ms/excavation/bcad'])) {
                                foreach ($rdfData[$beginUri]['https://purl.org/megalod/ms/excavation/bcad'] as $bcObj) {
                                    if ($bcObj['type'] === 'uri') {
                                        $parts = explode('/', $bcObj['value']);
                                        $bcacValue = end($parts);
                                        $beginningBC = ($bcacValue === 'BC');
                                    }
                                }
                            }
                        }
                    }
                }
                
                // Extract end
                if (isset($rdfData[$timelineUri]['http://www.w3.org/2006/time#hasEnd'])) {
                    foreach ($rdfData[$timelineUri]['http://www.w3.org/2006/time#hasEnd'] as $endObj) {
                        if ($endObj['type'] === 'uri' && isset($rdfData[$endObj['value']])) {
                            $endUri = $endObj['value'];
                            
                            // Extract year
                            if (isset($rdfData[$endUri]['http://www.w3.org/2006/time#inXSDgYear'])) {
                                foreach ($rdfData[$endUri]['http://www.w3.org/2006/time#inXSDgYear'] as $yearObj) {
                                    if ($yearObj['type'] === 'literal') {
                                        $endYear = $yearObj['value'];
                                    }
                                }
                            }
                            
                            // Extract BC/AC
                            if (isset($rdfData[$endUri]['https://purl.org/megalod/ms/excavation/bcad'])) {
                                foreach ($rdfData[$endUri]['https://purl.org/megalod/ms/excavation/bcad'] as $bcObj) {
                                    if ($bcObj['type'] === 'uri') {
                                        $parts = explode('/', $bcObj['value']);
                                        $bcacValue = end($parts);
                                        $endBC = ($bcacValue === 'BC');
                                    }
                                }
                            }
                        }
                    }
                }
                
                // Add beginning and end as separate properties
                if ($beginningYear) {
                    if (!isset($itemData['Beginning'])) {
                        $itemData['Beginning'] = [];
                    }
                    
                    $beginText = $beginningYear;
                    if ($beginningBC !== null) {
                        $beginText .= ' ' . ($beginningBC ? 'BC' : 'AC');
                    }
                    
                    $itemData['Beginning'][] = [
                        'type' => 'literal',
                        'property_id' => 7, // Use appropriate property ID
                        '@value' => $beginText
                    ];
                }
                
                if ($endYear) {
                    if (!isset($itemData['End'])) {
                        $itemData['End'] = [];
                    }
                    
                    $endText = $endYear;
                    if ($endBC !== null) {
                        $endText .= ' ' . ($endBC ? 'BC' : 'AC');
                    }
                    
                    $itemData['End'][] = [
                        'type' => 'literal',
                        'property_id' => 7, // Use appropriate property ID
                        '@value' => $endText
                    ];
                }
            }
        }
    }
}

/**
 * Extract SVU identifier
 */
private function extractSVUIdentifier($rdfData, $svuUri) {
    if (isset($rdfData[$svuUri]['http://purl.org/dc/terms/identifier'])) {
        foreach ($rdfData[$svuUri]['http://purl.org/dc/terms/identifier'] as $idObj) {
            if ($idObj['type'] === 'literal') {
                return $idObj['value'];
            }
        }
    }
    return null;
}

/**
 * Extract SVU description
 */
private function extractSVUDescription($rdfData, $svuUri) {
    if (isset($rdfData[$svuUri]['http://purl.org/dc/terms/description'])) {
        foreach ($rdfData[$svuUri]['http://purl.org/dc/terms/description'] as $descObj) {
            if ($descObj['type'] === 'literal') {
                return $descObj['value'];
            }
        }
    }
    return null;
}

/**
 * Extract timeline range as a formatted string
 */
private function extractTimelineRange($rdfData, $timelineUri) {
    $beginYear = null;
    $beginBC = null;
    $endYear = null;
    $endBC = null;
    
    // Extract beginning
    if (isset($rdfData[$timelineUri]['http://www.w3.org/2006/time#hasBeginning'])) {
        foreach ($rdfData[$timelineUri]['http://www.w3.org/2006/time#hasBeginning'] as $beginObj) {
            if ($beginObj['type'] === 'uri' && isset($rdfData[$beginObj['value']])) {
                $beginUri = $beginObj['value'];
                
                // Extract year
                if (isset($rdfData[$beginUri]['http://www.w3.org/2006/time#inXSDgYear'])) {
                    foreach ($rdfData[$beginUri]['http://www.w3.org/2006/time#inXSDgYear'] as $yearObj) {
                        if ($yearObj['type'] === 'literal') {
                            $beginYear = $yearObj['value'];
                        }
                    }
                }
                
                // Extract BC/AC
                if (isset($rdfData[$beginUri]['https://purl.org/megalod/ms/excavation/bcad'])) {
                    foreach ($rdfData[$beginUri]['https://purl.org/megalod/ms/excavation/bcad'] as $bcObj) {
                        if ($bcObj['type'] === 'uri') {
                            $parts = explode('/', $bcObj['value']);
                            $bcacValue = end($parts);
                            $beginBC = ($bcacValue === 'BC');
                        }
                    }
                }
            }
        }
    }
    
    // Extract end
    if (isset($rdfData[$timelineUri]['http://www.w3.org/2006/time#hasEnd'])) {
        foreach ($rdfData[$timelineUri]['http://www.w3.org/2006/time#hasEnd'] as $endObj) {
            if ($endObj['type'] === 'uri' && isset($rdfData[$endObj['value']])) {
                $endUri = $endObj['value'];
                
                // Extract year
                if (isset($rdfData[$endUri]['http://www.w3.org/2006/time#inXSDgYear'])) {
                    foreach ($rdfData[$endUri]['http://www.w3.org/2006/time#inXSDgYear'] as $yearObj) {
                        if ($yearObj['type'] === 'literal') {
                            $endYear = $yearObj['value'];
                        }
                    }
                }
                
                // Extract BC/AC
                if (isset($rdfData[$endUri]['https://purl.org/megalod/ms/excavation/bcad'])) {
                    foreach ($rdfData[$endUri]['https://purl.org/megalod/ms/excavation/bcad'] as $bcObj) {
                        if ($bcObj['type'] === 'uri') {
                            $parts = explode('/', $bcObj['value']);
                            $bcacValue = end($parts);
                            $endBC = ($bcacValue === 'BC');
                        }
                    }
                }
            }
        }
    }
    
    // Format timeline range
    if ($beginYear && $endYear) {
        $beginText = $beginYear;
        if ($beginBC !== null) {
            $beginText .= ' ' . ($beginBC ? 'BC' : 'AC');
        }
        
        $endText = $endYear;
        if ($endBC !== null) {
            $endText .= ' ' . ($endBC ? 'BC' : 'AC');
        }
        
        return "$beginText to $endText";
    } else if ($beginYear) {
        $beginText = $beginYear;
        if ($beginBC !== null) {
            $beginText .= ' ' . ($beginBC ? 'BC' : 'AC');
        }
        
        return "From $beginText";
    } else if ($endYear) {
        $endText = $endYear;
        if ($endBC !== null) {
            $endText .= ' ' . ($endBC ? 'BC' : 'AC');
        }
        
        return "Until $endText";
    }
    
    return null;
}


/**
 * REPLACE the extractCommonProperties method with this improved version
 */
private function extractCommonProperties($rdfData, $subject, &$itemData) {
    // Map common predicates to Omeka S properties with correct labels
    $commonPropertyMap = [
        'http://dbpedia.org/ontology/Annotation' => ['Description', 4], // Use description instead
        'http://www.cidoc-crm.org/cidoc-crm/E3_Condition_State' => ['Condition State', 476],
        'http://www.cidoc-crm.org/cidoc-crm/E55_Type' => ['Type', 399]
    ];
    
    foreach ($commonPropertyMap as $predicate => $mapping) {
        if (isset($rdfData[$subject][$predicate])) {
            $term = $mapping[0];
            $propertyId = $mapping[1];
            
            if (!isset($itemData[$term])) {
                $itemData[$term] = [];
            }
            
            foreach ($rdfData[$subject][$predicate] as $object) {
                if ($object['type'] === 'literal') {
                    // Handle boolean values properly
                    if ($object['value'] === 'true' || $object['value'] === 'false') {
                        $displayValue = ($object['value'] === 'true') ? 'True' : 'False';
                    } else {
                        $displayValue = $object['value'];
                    }
                    
                    $itemData[$term][] = [
                        'type' => 'literal',
                        'property_id' => $propertyId,
                        '@value' => $displayValue
                    ];
                } elseif ($object['type'] === 'uri') {
                    // Extract meaningful part from URI
                    if (strpos($object['value'], '/kos/') !== false) {
                        $parts = explode('/', $object['value']);
                        $value = ucfirst(end($parts));
                        
                        $itemData[$term][] = [
                            'type' => 'literal',
                            'property_id' => $propertyId,
                            '@value' => $value
                        ];
                    } else {
                        $itemData[$term][] = [
                            'type' => 'uri',
                            'property_id' => $propertyId,
                            '@id' => $object['value'],
                            'o:label' => $object['value']
                        ];
                    }
                }
            }
        }
    }
}

/**
 * REPLACE determineItemType method to handle all cases
 */
private function determineItemType($subjectType) {
    $typeMap = [
        'arrowhead' => 'Arrowhead',
        'item' => 'Archaeological Item', 
        'excavation' => 'Excavation',
        'context' => 'Context',
        'svu' => 'Stratigraphic Unit',
        'square' => 'Square',
        'unknown' => 'Archaeological Object'
    ];
    
    return $typeMap[$subjectType] ?? 'Archaeological Object';
}


    private function sendToOmekaS($omekaData, $itemSetId = null) {
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
                $createdItem = json_decode($response->getBody(), true);
                $itemId = $createdItem['o:id'];
                
                // Handle media files if they exist
                $this->attachMediaToItem($itemId);
                
                $createdItems[] = $createdItem;
                error_log('Omeka S Item Created Successfully: ID=' . $itemId);
            }
        }
    
        if ($itemSetId && !empty($createdItems) && $this->excavationData) {
            // Update the item set with excavation info
            $this->updateItemSetWithExcavationInfo($itemSetId, $this->excavationData);
        }
    
        return [
            'errors' => $errors,
            'created_items' => $createdItems
        ];
    }
    
    private function attachMediaToItem($itemId) {
        // Use stored files instead of $_FILES
        if ($this->uploadedFiles && isset($this->uploadedFiles['name']) && is_array($this->uploadedFiles['name'])) {
            $files = $this->uploadedFiles;
            
            foreach ($files['name'] as $index => $filename) {
                if (!empty($filename) && $files['error'][$index] === UPLOAD_ERR_OK) {
                    $tempFile = $files['tmp_name'][$index];
                    $mimeType = $files['type'][$index];
                    
                    // Create media via Omeka S API
                    $this->createMediaForItem($itemId, $tempFile, $filename, $mimeType);
                }
            }
        }
        error_log('DEBUG $_FILES: ' . print_r($_FILES, true), 3, OMEKA_PATH . '/logs/ddd.log');
        error_log('DEBUG item ID: ' . $itemId, 3, OMEKA_PATH . '/logs/ddd.log');
    }

    public function searchAction()
    {
        $request = $this->getRequest();
        $searchQuery = $request->getQuery('query', '');
        $searchType = $request->getQuery('type', 'all'); // 'items', 'item_sets', or 'all'
        $page = $request->getQuery('page', 1);
        $perPage = 20;
        
        $results = [];
        $totalItems = 0;
        $totalItemSets = 0;
        
        if ($searchQuery) {
            // Prepare search params
            $searchParams = [
                'page' => $page,
                'per_page' => $perPage
            ];
            
            // Add full-text search
            if (strlen($searchQuery) > 2) {
                $searchParams['fulltext_search'] = $searchQuery;
            }
            
            // Search item sets
            if ($searchType === 'all' || $searchType === 'item_sets') {
                try {
                    $itemSetResponse = $this->api()->search('item_sets', $searchParams);
                    $results['item_sets'] = $itemSetResponse->getContent();
                    $totalItemSets = $itemSetResponse->getTotalResults();
                } catch (\Exception $e) {
                    $this->logger()->err('Error searching item sets: ' . $e->getMessage());
                    $results['item_sets'] = [];
                    $totalItemSets = 0;
                }
            }
            
            // Search items
            if ($searchType === 'all' || $searchType === 'items') {
                try {
                    $itemResponse = $this->api()->search('items', $searchParams);
                    $results['items'] = $itemResponse->getContent();
                    $totalItems = $itemResponse->getTotalResults();
                } catch (\Exception $e) {
                    $this->logger()->err('Error searching items: ' . $e->getMessage());
                    $results['items'] = [];
                    $totalItems = 0;
                }
            }
        }
        
        $totalResults = $totalItems + $totalItemSets;
        
        return new ViewModel([
            'searchQuery' => $searchQuery,
            'searchType' => $searchType,
            'results' => $results,
            'totalResults' => $totalResults,
            'totalItems' => $totalItems,
            'totalItemSets' => $totalItemSets,
            'page' => $page,
            'perPage' => $perPage,
            'site' => $this->currentSite()
        ]);
    }
    
/**
 * View details for a specific item or item set
 */
public function viewDetailsAction()
{
    $request = $this->getRequest();
    $resourceType = $request->getQuery('type', 'item');
    $id = $request->getQuery('id');
    
    if (!$id) {
        return $this->redirect()->toRoute('site/add-triplestore/search', ['site-slug' => $this->currentSite()->slug()]);
    }
    
    $resource = null;
    $properties = [];
    $relatedItems = [];
    
    try {
        if ($resourceType === 'item_set') {
            $resource = $this->api()->read('item_sets', $id)->getContent();
            
            // Get items in this item set
            $relatedItems = $this->api()->search('items', [
                'item_set_id' => $id,
                'sort_by' => 'created',
                'sort_order' => 'desc',
                'per_page' => 50
            ])->getContent();
        } else {
            $resource = $this->api()->read('items', $id)->getContent();
        }
        
        // Get all values using the proper Omeka S method
        $values = $resource->values();
        
        // Debug: Let's see the actual structure for one property
        if (!empty($values)) {
            $firstTerm = array_keys($values)[0];
            $firstProperty = $values[$firstTerm];
        }
        
        foreach ($values as $term => $propertyData) {
            try {
                // Skip empty data
                if (empty($propertyData)) {
                    continue;
                }
                
                $propertyLabel = $this->getHumanReadableLabel($term); // Create readable labels from terms
                $propertyValues = [];
                
                // Handle different possible structures
                if (is_array($propertyData)) {
                    // Look for 'values' key specifically
                    if (isset($propertyData['values']) && is_array($propertyData['values'])) {
                        $propertyValues = $propertyData['values'];
                        
                        // Try to get better label from property object
                        if (isset($propertyData['property']) && is_object($propertyData['property'])) {
                            if (method_exists($propertyData['property'], 'label')) {
                                $propertyLabel = $propertyData['property']->label();
                            }
                        }
                    } else {
                        // Try each item in the array
                        foreach ($propertyData as $item) {
                            if (is_object($item) && method_exists($item, 'value')) {
                                $propertyValues[] = $item;
                                
                                // Try to get label from first value's property
                                if (empty($propertyValues) && method_exists($item, 'property')) {
                                    $prop = $item->property();
                                    if ($prop && method_exists($prop, 'label')) {
                                        $propertyLabel = $prop->label();
                                    }
                                }
                            }
                        }
                    }
                } else if (is_object($propertyData) && method_exists($propertyData, 'value')) {
                    // Single value object
                    $propertyValues = [$propertyData];
                    
                    if (method_exists($propertyData, 'property')) {
                        $prop = $propertyData->property();
                        if ($prop && method_exists($prop, 'label')) {
                            $propertyLabel = $prop->label();
                        }
                    }
                }
                
                // Only add if we have valid values
                if (!empty($propertyValues)) {
                    $properties[] = [
                        'term' => $term,
                        'label' => $propertyLabel,
                        'values' => $propertyValues
                    ];
                }
                
            } catch (\Exception $e) {
                error_log("Error processing property '$term': " . $e->getMessage(), 3, OMEKA_PATH . '/logs/property-debug.log');
                continue;
            }
        }
        
        // Sort properties by label for better display
        usort($properties, function($a, $b) {
            return strcmp($a['label'], $b['label']);
        });
        
    } catch (\Exception $e) {
        error_log('Error fetching resource details: ' . $e->getMessage(), 3, OMEKA_PATH . '/logs/view-details-error.log');
        $this->messenger()->addError('The requested resource could not be found.');
        return $this->redirect()->toRoute('site/add-triplestore/search', ['site-slug' => $this->currentSite()->slug()]);
    }
    
    return new ViewModel([
        'resource' => $resource,
        'resourceType' => $resourceType,
        'properties' => $properties,
        'relatedItems' => $relatedItems,
        'site' => $this->currentSite()
    ]);
}

/**
 * Convert a property term to a human-readable label
 */
private function getHumanReadableLabel($term)
{
    // Common property mappings
    $labelMappings = [
        'dcterms:title' => 'Title',
        'dcterms:identifier' => 'Identifier',
        'dcterms:description' => 'Description',
        'bibo:annotates' => 'Annotations',
        'crm:P44_has_condition' => 'Condition',
        'crm:P2_has_type' => 'Type',
        'crm:P43_has_dimension' => 'Dimension',
        'geo:lat' => 'Latitude',
        'geo:long' => 'Longitude',
        'ah:shape' => 'Shape',
        'ah:variant' => 'Variant',
        'ah:base' => 'Base',
        'ah:point' => 'Point',
        'ah:body' => 'Body',
        'ah:chippingMode' => 'Chipping Mode',
        'ah:chippingAmplitude' => 'Chipping Amplitude',
        'ah:chippingDirection' => 'Chipping Direction',
        'ah:chippingOrientation' => 'Chipping Orientation',
        'ah:chippingDelineation' => 'Chipping Delineation',
        'ah:chippingShape' => 'Chipping Shape',
        'excav:elongationIndex' => 'Elongation Index',
        'excav:thicknessIndex' => 'Thickness Index',
        'schema:height' => 'Height',
        'schema:width' => 'Width',
        'schema:depth' => 'Thickness',
        'schema:weight' => 'Weight',
    ];
    
    // Return mapped label if exists
    if (isset($labelMappings[$term])) {
        return $labelMappings[$term];
    }
    
    // Otherwise, create a readable label from the term
    // Remove namespace prefix
    $label = $term;
    if (strpos($label, ':') !== false) {
        $parts = explode(':', $label);
        $label = end($parts);
    }
    
    // Convert camelCase to Title Case
    $label = preg_replace('/([a-z])([A-Z])/', '$1 $2', $label);
    
    // Capitalize first letter of each word
    $label = ucwords($label);
    
    return $label;
}
    
    private function createMediaForItem($itemId, $tempFile, $filename, $mimeType) {
        $omekaBaseUrl = 'http://localhost/api';
        $omekaKeyIdentity = '2TGK0xT9tEMCUQs1178OyCnyRcIQpv5B';
        $omekaKeyCredential = '9IFd207Y8D5yG1bmtnCllmbgZweuMfQA';
        
        // Read file content
        $fileContent = file_get_contents($tempFile);
        $base64Content = base64_encode($fileContent);
        
        $mediaData = [
            'o:item' => ['o:id' => $itemId],
            'o:ingester' => 'upload',
            'dcterms:title' => [
                [
                    'type' => 'literal',
                    'property_id' => 1,
                    '@value' => $filename
                ]
            ],
            'o:source' => $filename,
            'ingest_file_data' => $base64Content,
            'ingest_filename' => $filename
        ];
        
        $client = new Client();
        $fullUrl = rtrim($omekaBaseUrl, '/') . '/media' . 
                   '?key_identity=' . urlencode($omekaKeyIdentity) .
                   '&key_credential=' . urlencode($omekaKeyCredential);
        
        $client->setUri($fullUrl);
        $client->setMethod('POST');
        $client->setHeaders(['Content-Type' => 'application/json']);
        $client->setRawBody(json_encode($mediaData));
        
        $response = $client->send();
        
        if ($response->isSuccess()) {
            error_log('Media created successfully for item ' . $itemId . ': ' . $filename);
        } else {
            error_log('Failed to create media: ' . $response->getBody());
        }
    }
}