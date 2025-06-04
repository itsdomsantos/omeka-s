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
    
    private $currentProcessingItemSetId = null; // Track current item set being processed
        

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
    // Get all POST data from the collecting form
    $formData = $this->params()->fromPost();
    
    error_log('Received excavation collecting form data: ' . print_r($formData, true), 3, OMEKA_PATH . '/logs/excavation-collecting-form.log');
    
    // Transform collecting form data to excavation format
    $excavationData = $this->transformCollectingFormToExcavationData($formData);
    
    if (!empty($excavationData)) {
        // Generate excavation identifier
        $excavationIdentifier = $excavationData['excavation_id'] ?? 'EXC-' . uniqid();
        
        // Create TTL data from the excavation form
        $ttlData = $this->processExcavationFormData($excavationData, $excavationIdentifier);
        
        // Create item set first
        $itemSetData = $this->createExcavationItemSetData($excavationIdentifier, $excavationData);
        
        try {
            // Create the item set
            $response = $this->api()->create('item_sets', $itemSetData);
            if ($response) {
                $newItemSet = $response->getContent();
                $itemSetId = $newItemSet->id();
                
                // Store the mapping between item set and excavation
                $this->storeMappingBetweenItemSetAndExcavation($itemSetId, $excavationIdentifier);
                
                // Upload TTL data to triplestore
                $result = $this->uploadTtlData($ttlData, $itemSetId);
                
                error_log('Excavation form processing result: ' . $result, 3, OMEKA_PATH . '/logs/excavation-collecting-form.log');
                
                // Redirect to arrowhead upload page with success message
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
        } catch (\Exception $e) {
            error_log('Error creating excavation item set: ' . $e->getMessage(), 3, OMEKA_PATH . '/logs/excavation-collecting-form.log');
            return $this->redirect()->toUrl($this->url()->fromRoute('site', [
                'site-slug' => $this->currentSite()->slug()
            ], [
                'query' => [
                    'result' => 'Error: Failed to create excavation - ' . $e->getMessage()
                ]
            ]));
        }
    }
    
    // If transformation failed, redirect with error
    return $this->redirect()->toUrl($this->url()->fromRoute('site', [
        'site-slug' => $this->currentSite()->slug()
    ], [
        'query' => [
            'result' => 'Error: Could not process excavation form data'
        ]
    ]));
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
 * Process archaeologist data from the collecting form
 */
private function processArchaeologistDataFromForm($formData)
{
    $archaeologistData = [
        'existing' => false,
        'name' => null,
        'orcid' => null,
        'email' => null
    ];
    
    // Check if existing archaeologist was selected
    if (isset($formData['existing_archaeologist']) && !empty($formData['existing_archaeologist'])) {
        $archaeologistData['existing'] = true;
        $archaeologistData['item_id'] = $formData['existing_archaeologist'];
        
        // Get the archaeologist data from Omeka
        try {
            $archaeologist = $this->api()->read('items', $formData['existing_archaeologist'])->getContent();
            $values = $archaeologist->values();
            
            // Extract name, ORCID, and email from the archaeologist item
            foreach ($values as $term => $propertyValues) {
                if (!empty($propertyValues) && isset($propertyValues[0])) {
                    $property = $propertyValues[0]->property();
                    if ($property) {
                        $label = $property->label();
                        $value = $propertyValues[0]->value();
                        
                        if (stripos($label, 'name') !== false) {
                            $archaeologistData['name'] = $value;
                        } elseif (stripos($label, 'orcid') !== false || stripos($label, 'account') !== false) {
                            $archaeologistData['orcid'] = str_replace('https://orcid.org/', '', $value);
                        } elseif (stripos($label, 'email') !== false || stripos($label, 'mbox') !== false) {
                            $archaeologistData['email'] = str_replace('mailto:', '', $value);
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            error_log('Error loading existing archaeologist: ' . $e->getMessage(), 3, OMEKA_PATH . '/logs/excavation-transform.log');
        }
    } else {
        // New archaeologist data
        $archaeologistData['existing'] = false;
        $archaeologistData['name'] = $formData['new_archaeologist_name'] ?? null;
        $archaeologistData['orcid'] = $formData['new_archaeologist_orcid'] ?? null;
        $archaeologistData['email'] = $formData['new_archaeologist_email'] ?? null;
    }
    
    return $archaeologistData;
}





/**
 * Create a proper URL slug from a string
 */
private function createUrlSlug($string) {
    // Convert to lowercase
    $slug = strtolower($string);
    
    // Replace spaces and special characters with hyphens
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    
    // Remove leading/trailing hyphens
    $slug = trim($slug, '-');
    
    // Handle empty slugs
    if (empty($slug)) {
        $slug = 'unknown';
    }
    
    return $slug;
}

/**
 * Process archaeologist data for TTL generation - IMPROVED URI
 */
private function processArchaeologistForTtl($archaeologistData, $baseUri)
{
    if ($archaeologistData['existing'] && !empty($archaeologistData['item_id'])) {
        // Use existing archaeologist - create URI based on item ID
        return "$baseUri/archaeologist/item-" . $archaeologistData['item_id'];
    } elseif (!empty($archaeologistData['name'])) {
        // Create new archaeologist with readable URI
        $nameSlug = $this->createUrlSlug($archaeologistData['name']);
        return "$baseUri/archaeologist/$nameSlug";
    }
    
    return null;
}




/**
 * Generate context TTL with proper SVU relationships
 */
private function generateContextTtl($contextUri, $context, $allEntities, $baseUri)
{
    $ttl = "<$contextUri> a excav:Context ;\n";
    $ttl .= "    dct:identifier \"" . $context['context_id'] . "\"^^xsd:literal ;\n";
    
    if (!empty($context['context_description'])) {
        $ttl .= "    dct:description \"" . $context['context_description'] . "\"^^xsd:literal ;\n";
    }
    
    // Link to SVUs based on relationships
    if (!empty($allEntities['relationships'])) {
        foreach ($allEntities['relationships'] as $relationship) {
            // Find the context index that matches this context
            $contextFound = false;
            foreach ($allEntities['contexts'] as $ctxIndex => $ctx) {
                if ($ctx['context_id'] === $context['context_id'] && $relationship['context'] == $ctxIndex) {
                    $contextFound = true;
                    break;
                }
            }
            
            if ($contextFound && isset($allEntities['svus'][$relationship['svu']])) {
                $svu = $allEntities['svus'][$relationship['svu']];
                $svuUri = "$baseUri/svu/" . $this->sanitizeForUri($svu['svu_id']);
                $ttl .= "    excav:hasSVU <$svuUri> ;\n";
            }
        }
    }
    
    $ttl .= "    .\n\n";
    
    return $ttl;
}




/**
 * FIXED: Generate clean location TTL without duplicate URI declarations
 */
private function generateEnhancedLocationTtl($locationUri, $gpsUri, $excavationData)
{
    $ttl = "";
    
    // MAIN LOCATION ENTITY - clean single type
    $ttl .= "<$locationUri> a excav:Location ;\n";
    
    // Use site_name as the informationName
    if (!empty($excavationData['site_name'])) {
        $ttl .= "    dbo:informationName \"" . $excavationData['site_name'] . "\"^^xsd:literal ;\n";
    }
    
    // Add district/parish with lowercase property names and normalized URIs
    $baseUri = dirname(dirname($locationUri)); // Get base URI from location URI
    
    if (!empty($excavationData['district'])) {
        $districtSlug = $this->createUrlSlug($excavationData['district']);
        $districtUri = "$baseUri/$districtSlug";
        $ttl .= "    dbo:district <$districtUri> ;\n";
    }
    
    if (!empty($excavationData['parish'])) {
        $parishSlug = $this->createUrlSlug($excavationData['parish']);
        $parishUri = "$baseUri/$parishSlug";
        $ttl .= "    dbo:parish <$parishUri> ;\n";
    }
    
    // Country as DBpedia resource (uppercase 'Country')
    if (!empty($excavationData['country'])) {
        $countrySlug = str_replace(' ', '_', $excavationData['country']);
        $countryUri = "http://dbpedia.org/resource/" . $countrySlug;
        $ttl .= "    dbo:Country <$countryUri> ;\n";
    }
    
    // FIXED: Reference to separate GPS coordinates object
    $ttl .= "    excav:hasGPSCoordinates <$gpsUri> .\n\n";
    
    // SEPARATE GPS COORDINATES OBJECT - clean structure
    $ttl .= "<$gpsUri> a excav:GPSCoordinates ;\n";
    
    if (!empty($excavationData['latitude'])) {
        $ttl .= "    geo:lat \"" . $excavationData['latitude'] . "\"^^xsd:decimal ;\n";
    }
    
    if (!empty($excavationData['longitude'])) {
        $ttl .= "    geo:long \"" . $excavationData['longitude'] . "\"^^xsd:decimal .\n\n";
    } else {
        $ttl .= "    .\n\n";
    }
    
    // Add simple entity declarations for district/parish
    if (!empty($excavationData['district'])) {
        $districtSlug = $this->createUrlSlug($excavationData['district']);
        $districtUri = "$baseUri/$districtSlug";
        $ttl .= "<$districtUri> a dbo:District .\n";
    }
    
    if (!empty($excavationData['parish'])) {
        $parishSlug = $this->createUrlSlug($excavationData['parish']);
        $parishUri = "$baseUri/$parishSlug";
        $ttl .= "<$parishUri> a dbo:Parish .\n";
    }
    
    $ttl .= "\n";
    
    return $ttl;
}

// CRITICAL: Add this method to prevent KOS URI normalization in the normalizeUris method

private function normalizeUris($ttlData, $itemSetId) {
    error_log("=== FIXED URI NORMALIZATION START ===", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
    error_log("ItemSetId: $itemSetId", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
    
    if (!$itemSetId) {
        error_log("No item set ID provided, skipping normalization", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
        return $ttlData;
    }
    
    // 1. First, extract the main item identifier
    $mainIdentifier = null;
    if (preg_match('/dct:identifier\s+"([^"]+)"/i', $ttlData, $matches)) {
        $mainIdentifier = $matches[1]; // e.g., "AH-003"
        error_log("Main identifier found: $mainIdentifier", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
    }
    
    $modifiedTtl = $ttlData;
    $replacements = 0;
    
    // CRITICAL FIX: Replace excavation ID patterns with item set ID
    // This pattern will match excavation IDs like PRD-01 in URIs
    $excavationIdPattern = '/https:\/\/purl\.org\/megalod\/([A-Za-z0-9-]+)\/([^>]+)>/';
    $modifiedTtl = preg_replace_callback(
        $excavationIdPattern,
        function($matches) use ($itemSetId, &$replacements) {
            // Skip KOS namespaces
            if (strpos($matches[0], '/kos/') !== false) {
                return $matches[0];
            }
            
            $resourcePath = $matches[2];
            $excavationId = $matches[1];
            
            // Only replace if it looks like an excavation ID (not already an item set ID)
            if (!is_numeric($excavationId) && $excavationId != $itemSetId) {
                $replacements++;
                error_log("Replacing excavation ID in URI: {$matches[0]} → <https://purl.org/megalod/$itemSetId/$resourcePath>", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
                return "<https://purl.org/megalod/$itemSetId/$resourcePath>";
            }
            
            return $matches[0];
        },
        $modifiedTtl
    );
    
    // 2. Apply URI transformations with proper regex handling for specific patterns
    
    // Main item URI: /item/arrowhead-003 → /AH-003
    $modifiedTtl = preg_replace_callback(
        '/<https:\/\/purl\.org\/megalod\/item\/[^>]+>/',
        function($matches) use ($itemSetId, $mainIdentifier, &$replacements) {
            if (strpos($matches[0], '/kos/') !== false) return $matches[0];
            $replacements++;
            error_log("Replacing main item URI: {$matches[0]} → <https://purl.org/megalod/$itemSetId/$mainIdentifier>", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
            return "<https://purl.org/megalod/$itemSetId/$mainIdentifier>";
        },
        $modifiedTtl
    );
    
    // Coordinates: /coordinates/ah-003 → /AH-003/coordinates
    $modifiedTtl = preg_replace_callback(
        '/<https:\/\/purl\.org\/megalod\/coordinates\/([^>]+)>/',
        function($matches) use ($itemSetId, $mainIdentifier, &$replacements) {
            if (strpos($matches[0], '/kos/') !== false) return $matches[0];
            $replacements++;
            error_log("Replacing coordinates URI: {$matches[0]} → <https://purl.org/megalod/$itemSetId/$mainIdentifier/coordinates>", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
            return "<https://purl.org/megalod/$itemSetId/$mainIdentifier/coordinates>";
        },
        $modifiedTtl
    );
    
    // SVU: explicitly normalize SVU URIs
    $modifiedTtl = preg_replace_callback(
        '/<https:\/\/purl\.org\/megalod\/[^\/]+\/svu\/([^>]+)>/',
        function($matches) use ($itemSetId, &$replacements) {
            if (strpos($matches[0], '/kos/') !== false) return $matches[0];
            $svuId = $matches[1];
            $replacements++;
            error_log("Fixing SVU URI: {$matches[0]} → <https://purl.org/megalod/$itemSetId/svu/$svuId>", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
            return "<https://purl.org/megalod/$itemSetId/svu/$svuId>";
        },
        $modifiedTtl
    );
    
    // Context: explicitly normalize context URIs
    $modifiedTtl = preg_replace_callback(
        '/<https:\/\/purl\.org\/megalod\/[^\/]+\/context\/([^>]+)>/',
        function($matches) use ($itemSetId, &$replacements) {
            if (strpos($matches[0], '/kos/') !== false) return $matches[0];
            $contextId = $matches[1];
            $replacements++;
            error_log("Fixing context URI: {$matches[0]} → <https://purl.org/megalod/$itemSetId/context/$contextId>", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
            return "<https://purl.org/megalod/$itemSetId/context/$contextId>";
        },
        $modifiedTtl
    );
    
    // Square: explicitly normalize square URIs
    $modifiedTtl = preg_replace_callback(
        '/<https:\/\/purl\.org\/megalod\/[^\/]+\/square\/([^>]+)>/',
        function($matches) use ($itemSetId, &$replacements) {
            if (strpos($matches[0], '/kos/') !== false) return $matches[0];
            $squareId = $matches[1];
            $replacements++;
            error_log("Fixing square URI: {$matches[0]} → <https://purl.org/megalod/$itemSetId/square/$squareId>", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
            return "<https://purl.org/megalod/$itemSetId/square/$squareId>";
        },
        $modifiedTtl
    );
    
    // Continue with other specific patterns...
    // [Keep all your existing specific patterns below]
    
    // Typometry values: /typometry/ah-003-height → /AH-003/typometry/height
    $modifiedTtl = preg_replace_callback(
        '/<https:\/\/purl\.org\/megalod\/typometry\/[^-]+-[^-]+-([^>]+)>/',
        function($matches) use ($itemSetId, $mainIdentifier, &$replacements) {
            if (strpos($matches[0], '/kos/') !== false) return $matches[0];
            $measurement = $matches[1]; // height, width, depth, x, y, z
            $replacements++;
            error_log("Replacing typometry URI: {$matches[0]} → <https://purl.org/megalod/$itemSetId/$mainIdentifier/typometry/$measurement>", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
            return "<https://purl.org/megalod/$itemSetId/$mainIdentifier/typometry/$measurement>";
        },
        $modifiedTtl
    );
    
    // Weight: /weight/ah-003 → /AH-003/weight
    $modifiedTtl = preg_replace_callback(
        '/<https:\/\/purl\.org\/megalod\/weight\/[^>]+>/',
        function($matches) use ($itemSetId, $mainIdentifier, &$replacements) {
            if (strpos($matches[0], '/kos/') !== false) return $matches[0];
            $replacements++;
            error_log("Replacing weight URI: {$matches[0]} → <https://purl.org/megalod/$itemSetId/$mainIdentifier/weight>", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
            return "<https://purl.org/megalod/$itemSetId/$mainIdentifier/weight>";
        },
        $modifiedTtl
    );
    
    // Morphology: /Morphology/ah-003 → /AH-003/morphology
    $modifiedTtl = preg_replace_callback(
        '/<https:\/\/purl\.org\/megalod\/Morphology\/[^>]+>/',
        function($matches) use ($itemSetId, $mainIdentifier, &$replacements) {
            if (strpos($matches[0], '/kos/') !== false) return $matches[0];
            $replacements++;
            error_log("Replacing morphology URI: {$matches[0]} → <https://purl.org/megalod/$itemSetId/$mainIdentifier/morphology>", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
            return "<https://purl.org/megalod/$itemSetId/$mainIdentifier/morphology>";
        },
        $modifiedTtl
    );
    
    // Chipping: /Chipping/ah-003 → /AH-003/chipping
    $modifiedTtl = preg_replace_callback(
        '/<https:\/\/purl\.org\/megalod\/Chipping\/[^>]+>/',
        function($matches) use ($itemSetId, $mainIdentifier, &$replacements) {
            if (strpos($matches[0], '/kos/') !== false) return $matches[0];
            $replacements++;
            error_log("Replacing chipping URI: {$matches[0]} → <https://purl.org/megalod/$itemSetId/$mainIdentifier/chipping>", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
            return "<https://purl.org/megalod/$itemSetId/$mainIdentifier/chipping>";
        },
        $modifiedTtl
    );
    
    // Body Length: /BodyLength/ah-003 → /AH-003/bodyLength
    $modifiedTtl = preg_replace_callback(
        '/<https:\/\/purl\.org\/megalod\/BodyLength\/[^>]+>/',
        function($matches) use ($itemSetId, $mainIdentifier, &$replacements) {
            if (strpos($matches[0], '/kos/') !== false) return $matches[0];
            $replacements++;
            error_log("Replacing bodyLength URI: {$matches[0]} → <https://purl.org/megalod/$itemSetId/$mainIdentifier/bodyLength>", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
            return "<https://purl.org/megalod/$itemSetId/$mainIdentifier/bodyLength>";
        },
        $modifiedTtl
    );
    
    // Base Length: /BaseLength/ah-003 → /AH-003/baseLength
    $modifiedTtl = preg_replace_callback(
        '/<https:\/\/purl\.org\/megalod\/BaseLength\/[^>]+>/',
        function($matches) use ($itemSetId, $mainIdentifier, &$replacements) {
            if (strpos($matches[0], '/kos/') !== false) return $matches[0];
            $replacements++;
            error_log("Replacing baseLength URI: {$matches[0]} → <https://purl.org/megalod/$itemSetId/$mainIdentifier/baseLength>", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
            return "<https://purl.org/megalod/$itemSetId/$mainIdentifier/baseLength>";
        },
        $modifiedTtl
    );
    
    // Context references: /context/CTX-001 → /context/CTX-001
    $modifiedTtl = preg_replace_callback(
        '/<https:\/\/purl\.org\/megalod\/context\/([^>]+)>/',
        function($matches) use ($itemSetId, &$replacements) {
            if (strpos($matches[0], '/kos/') !== false) return $matches[0];
            $contextId = $matches[1]; // CTX-001
            $replacements++;
            error_log("Replacing context URI: {$matches[0]} → <https://purl.org/megalod/$itemSetId/context/$contextId>", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
            return "<https://purl.org/megalod/$itemSetId/context/$contextId>";
        },
        $modifiedTtl
    );
    
    // SVU references: /svu/Layer-002 → /svu/Layer-002
    $modifiedTtl = preg_replace_callback(
        '/<https:\/\/purl\.org\/megalod\/svu\/([^>]+)>/',
        function($matches) use ($itemSetId, &$replacements) {
            if (strpos($matches[0], '/kos/') !== false) return $matches[0];
            $svuId = $matches[1]; // Layer-002
            $replacements++;
            error_log("Replacing SVU URI: {$matches[0]} → <https://purl.org/megalod/$itemSetId/svu/$svuId>", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
            return "<https://purl.org/megalod/$itemSetId/svu/$svuId>";
        },
        $modifiedTtl
    );
    
    // Square references: /square/B1 → /square/B1
    $modifiedTtl = preg_replace_callback(
        '/<https:\/\/purl\.org\/megalod\/square\/([^>]+)>/',
        function($matches) use ($itemSetId, &$replacements) {
            if (strpos($matches[0], '/kos/') !== false) return $matches[0];
            $squareId = $matches[1]; // B1
            $replacements++;
            error_log("Replacing square URI: {$matches[0]} → <https://purl.org/megalod/$itemSetId/square/$squareId>", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
            return "<https://purl.org/megalod/$itemSetId/square/$squareId>";
        },
        $modifiedTtl
    );
    
    // Location references: /location/alto-castelinho → /location/alto-castelinho
    $modifiedTtl = preg_replace_callback(
        '/<https:\/\/purl\.org\/megalod\/location\/([^>]+)>/',
        function($matches) use ($itemSetId, &$replacements) {
            if (strpos($matches[0], '/kos/') !== false) return $matches[0];
            $locationId = $matches[1]; // alto-castelinho
            $replacements++;
            error_log("Replacing location URI: {$matches[0]} → <https://purl.org/megalod/$itemSetId/location/$locationId>", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
            return "<https://purl.org/megalod/$itemSetId/location/$locationId>";
        },
        $modifiedTtl
    );
    
    // Material references: /material/flint → /material/flint
    $modifiedTtl = preg_replace_callback(
        '/<https:\/\/purl\.org\/megalod\/material\/([^>]+)>/',
        function($matches) use ($itemSetId, &$replacements) {
            if (strpos($matches[0], '/kos/') !== false) return $matches[0];
            $materialId = $matches[1]; // flint
            $replacements++;
            error_log("Replacing material URI: {$matches[0]} → <https://purl.org/megalod/$itemSetId/material/$materialId>", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
            return "<https://purl.org/megalod/$itemSetId/material/$materialId>";
        },
        $modifiedTtl
    );
    
    // Main excavation URI
    $modifiedTtl = preg_replace_callback(
        '/<https:\/\/purl\.org\/megalod\/excavation\/([^>]+)>/',
        function($matches) use ($itemSetId, $mainIdentifier, &$replacements) {
            $replacements++;
            return "<https://purl.org/megalod/$itemSetId/excavation/$mainIdentifier>";
        },
        $modifiedTtl
    );
    
    // Archaeologist URI
    $modifiedTtl = preg_replace_callback(
        '/<https:\/\/purl\.org\/megalod\/archaeologist\/([^>]+)>/',
        function($matches) use ($itemSetId, &$replacements) {
            $replacements++;
            return "<https://purl.org/megalod/$itemSetId/archaeologist/{$matches[1]}>";
        },
        $modifiedTtl
    );
    
    // GPS URI
    $modifiedTtl = preg_replace_callback(
        '/<https:\/\/purl\.org\/megalod\/gps\/([^>]+)>/',
        function($matches) use ($itemSetId, &$replacements) {
            $replacements++;
            return "<https://purl.org/megalod/$itemSetId/gps/{$matches[1]}>";
        },
        $modifiedTtl
    );
    
    // In normalizeUris method, add this check for BC/AD URIs:
    $modifiedTtl = preg_replace_callback(
        '/<https:\/\/purl\.org\/megalod\/([^\/]+)\/MegaLOD-BCAD\/(BC|AD)>/',
        function($matches) {
            // Always use the correct KOS namespace for BC/AD
            return "<https://purl.org/megalod/kos/MegaLOD-BCAD/{$matches[2]}>";
        },
        $modifiedTtl
    );
    
    $modifiedTtl = preg_replace_callback(
        '/<https:\/\/purl\.org\/megalod\/([^\/]+)\/excavation\/([^>]+)>/',
        function($matches) use ($itemSetId, &$replacements) {
            if (strpos($matches[0], '/kos/') !== false) return $matches[0];
            $excavationId = $matches[2]; // PRD-01
            $replacements++;
            return "<https://purl.org/megalod/$itemSetId/excavation/$excavationId>";
        },
        $modifiedTtl
    );
    
    // Timeline URIs: /timeline/iron-age → /2581/timeline/iron-age
    $modifiedTtl = preg_replace_callback(
        '/<https:\/\/purl\.org\/megalod\/timeline\/([^>]+)>/',
        function($matches) use ($itemSetId, &$replacements) {
            if (strpos($matches[0], '/kos/') !== false) return $matches[0];
            $replacements++;
            return "<https://purl.org/megalod/$itemSetId/timeline/{$matches[1]}>";
        },
        $modifiedTtl
    );
    
    // Instant URIs: /instant/800bc → /2581/instant/800bc  
    $modifiedTtl = preg_replace_callback(
        '/<https:\/\/purl\.org\/megalod\/instant\/([^>]+)>/',
        function($matches) use ($itemSetId, &$replacements) {
            if (strpos($matches[0], '/kos/') !== false) return $matches[0];
            $replacements++;
            return "<https://purl.org/megalod/$itemSetId/instant/{$matches[1]}>";
        },
        $modifiedTtl
    );
    
    error_log("=== URI NORMALIZATION COMPLETE ===", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
    error_log("Total replacements made: $replacements", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
    
    return $modifiedTtl;
}
/**
 * FIXED: Updated SVU TTL generation to use correct timeline URI structure
 */
private function generateSvuTtl($svuUri, $svu)
{
    $ttl = "<$svuUri> a excav:StratigraphicVolumeUnit ;\n";
    $ttl .= "    dct:identifier \"" . $svu['svu_id'] . "\"^^xsd:literal ;\n";
    
    if (!empty($svu['svu_description'])) {
        $ttl .= "    dct:description \"" . $svu['svu_description'] . "\"^^xsd:literal ;\n";
    }
    
    // Add timeline if year data is provided - use consistent URI structure
    if (!empty($svu['svu_lower_year']) || !empty($svu['svu_upper_year'])) {
        // Extract base URI from SVU URI to build timeline URI
        $baseUri = dirname(dirname($svuUri)); // Get the base URI (e.g., https://purl.org/megalod/2422)
        $svuSlug = basename($svuUri); // Get just the SVU identifier part
        $timelineUri = "$baseUri/timeline/$svuSlug";
        
        $ttl .= "    excav:hasTimeline <$timelineUri> ;\n";
    }
    
    $ttl .= "    .\n\n";
    
    return $ttl;
}

/**
 * UPDATED: Process excavation form data without generating duplicate declarations
 */
private function processExcavationFormData($excavationData, $excavationIdentifier)
{
    error_log('Processing excavation form data for: ' . $excavationIdentifier, 3, OMEKA_PATH . '/logs/excavation-ttl.log');
    
    // USE THE EXCAVATION IDENTIFIER INSTEAD OF RANDOM HASH
    $baseUri = "https://purl.org/megalod/" . $excavationIdentifier;
    $excavationUri = "$baseUri/excavation/$excavationIdentifier";
    
    // Create consistent, readable URIs
    $siteName = $excavationData['site_name'] ?? 'unknown';
    $siteSlug = $this->createUrlSlug($siteName);
    $locationUri = "$baseUri/location/$siteSlug";
    $gpsUri = "$baseUri/gps/$siteSlug"; // SEPARATE GPS URI
    
    // Build TTL data
    $ttl = $this->getTtlPrefixes();
    
    // MAIN EXCAVATION SECTION
    $ttl .= "# ========================================================================================\n";
    $ttl .= "# EXCAVATION DATA - " . strtoupper($excavationData['site_name'] ?? 'ARCHAEOLOGICAL SITE') . "\n";
    $ttl .= "# ========================================================================================\n\n";
    
    $ttl .= "# =========== MAIN EXCAVATION ===========\n\n";
    
    // Add excavation
    $ttl .= "<$excavationUri> a excav:Excavation ;\n";
    $ttl .= "    dct:identifier \"$excavationIdentifier\"^^xsd:literal ;\n";
    $ttl .= "    dul:hasLocation <$locationUri> ;\n";
    
    // Add archaeologist reference
    if (!empty($excavationData['archaeologist']['name'])) {
        $archaeologistUri = $this->processArchaeologistForTtl($excavationData['archaeologist'], $baseUri);
        if ($archaeologistUri) {
            $ttl .= "    excav:hasPersonInCharge <$archaeologistUri> ;\n";
        }
    }
    
    // Add squares
    if (!empty($excavationData['entities']['squares'])) {
        $squareUris = [];
        foreach ($excavationData['entities']['squares'] as $square) {
            $squareSlug = $this->createUrlSlug($square['square_id']);
            $squareUri = "$baseUri/square/$squareSlug";
            $squareUris[] = "<$squareUri>";
        }
        $ttl .= "    excav:hasSquare " . implode(",\n                    ", $squareUris) . " ;\n";
    }
    
    // Add contexts
    if (!empty($excavationData['entities']['contexts'])) {
        $contextUris = [];
        foreach ($excavationData['entities']['contexts'] as $context) {
            $contextSlug = $this->createUrlSlug($context['context_id']);
            $contextUri = "$baseUri/context/$contextSlug";
            $contextUris[] = "<$contextUri>";
        }
        $ttl .= "    excav:hasContext " . implode(",\n                     ", $contextUris) . " .\n\n";
    } else {
        $ttl .= "    .\n\n";
    }
    
    // LOCATION SECTION
    $ttl .= "# =========== LOCATION ===========\n\n";
    $ttl .= $this->generateEnhancedLocationTtl($locationUri, $gpsUri, $excavationData);
    
    // Continue with other sections...
    // (ARCHAEOLOGIST, SQUARES, CONTEXTS, SVUS, TIMELINES sections remain the same)
    
    // ARCHAEOLOGIST SECTION
    if (!empty($excavationData['archaeologist']['name']) && !$excavationData['archaeologist']['existing']) {
        $ttl .= "# =========== ARCHAEOLOGIST ===========\n\n";
        $archaeologistUri = $this->processArchaeologistForTtl($excavationData['archaeologist'], $baseUri);
        $ttl .= $this->generateArchaeologistTtl($archaeologistUri, $excavationData['archaeologist']);
    }
    
    // SQUARES SECTION
    if (!empty($excavationData['entities']['squares'])) {
        $ttl .= "# =========== EXCAVATION SQUARES ===========\n\n";
        foreach ($excavationData['entities']['squares'] as $square) {
            $squareSlug = $this->createUrlSlug($square['square_id']);
            $squareUri = "$baseUri/square/$squareSlug";
            $ttl .= $this->generateSquareTtl($squareUri, $square);
        }
    }
    
    // CONTEXTS SECTION
    if (!empty($excavationData['entities']['contexts'])) {
        $ttl .= "# =========== CONTEXTS ===========\n\n";
        foreach ($excavationData['entities']['contexts'] as $context) {
            $contextSlug = $this->createUrlSlug($context['context_id']);
            $contextUri = "$baseUri/context/$contextSlug";
            $ttl .= $this->generateContextTtl($contextUri, $context, $excavationData['entities'], $baseUri);
        }
    }
    
    // SVUS SECTION
    if (!empty($excavationData['entities']['svus'])) {
        $ttl .= "# =========== STRATIGRAPHIC VOLUME UNITS ===========\n\n";
        foreach ($excavationData['entities']['svus'] as $svu) {
            $svuSlug = $this->createUrlSlug($svu['svu_id']);
            $svuUri = "$baseUri/svu/$svuSlug";
            $ttl .= $this->generateSvuTtl($svuUri, $svu);
        }
    }
    
    // Generate timeline and instant sections if we have SVUs with dates
    $this->generateTimelineAndInstantSections($ttl, $excavationData, $baseUri);
    
    error_log('Generated clean TTL for excavation: ' . $excavationIdentifier, 3, OMEKA_PATH . '/logs/excavation-ttl.log');
    
    return $ttl;
}




/**
 * NEW: Generate timeline and instant sections like file uploads
 */
private function generateTimelineAndInstantSections(&$ttl, $excavationData, $baseUri) {
    if (empty($excavationData['entities']['svus'])) {
        return;
    }
    
    $timelineUris = [];
    $instantUris = [];
    
    // Collect all timeline URIs first
    foreach ($excavationData['entities']['svus'] as $svu) {
        if (!empty($svu['svu_lower_year']) || !empty($svu['svu_upper_year'])) {
            $svuSlug = $this->createUrlSlug($svu['svu_id']);
            $timelineUri = "$baseUri/timeline/$svuSlug";
            $timelineUris[] = [
                'uri' => $timelineUri,
                'svu' => $svu
            ];
        }
    }
    
    if (!empty($timelineUris)) {
        $ttl .= "# =========== TIMELINES ===========\n\n";
        
        foreach ($timelineUris as $timelineData) {
            $timeline = $timelineData['uri'];
            $svu = $timelineData['svu'];
            
            $ttl .= "<$timeline> a excav:TimeLine ;\n";
            
            if (!empty($svu['svu_lower_year'])) {
                $beginInstantUri = "$timeline/beginning";
                $ttl .= "    time:hasBeginning <$beginInstantUri> ;\n";
                $instantUris[] = [
                    'uri' => $beginInstantUri,
                    'year' => $svu['svu_lower_year'],
                    'bc' => !empty($svu['svu_lower_bc'])
                ];
            }
            
            if (!empty($svu['svu_upper_year'])) {
                $endInstantUri = "$timeline/end";
                $ttl .= "    time:hasEnd <$endInstantUri> .\n\n";
                $instantUris[] = [
                    'uri' => $endInstantUri,
                    'year' => $svu['svu_upper_year'],
                    'bc' => !empty($svu['svu_upper_bc'])
                ];
            } else {
                $ttl .= "    .\n\n";
            }
        }
        
        if (!empty($instantUris)) {
            $ttl .= "# =========== TIME INSTANTS ===========\n\n";
            
            foreach ($instantUris as $instantData) {
                $instantUri = $instantData['uri'];
                $year = $instantData['year'];
                $isBC = $instantData['bc'];
                
                $ttl .= "<$instantUri> a excav:Instant ;\n";
                $ttl .= "    excav:bcad <https://purl.org/megalod/kos/MegaLOD-BCAD/" . ($isBC ? 'BC' : 'AD') . "> ;\n";
                
                // Format year properly for xsd:gYear
                $yearValue = abs((int)$year);
                $yearFormatted = str_pad($yearValue, 4, '0', STR_PAD_LEFT);
                
                // Add negative sign for BC years in xsd:gYear format
                if ($isBC) {
                    $yearFormatted = "-" . $yearFormatted;
                }
                
                $ttl .= "    time:inXSDgYear \"$yearFormatted\"^^xsd:gYear .\n\n";
            }
        }
    }
}



/**
 * ENHANCED: Generate archaeologist TTL with proper URI structure
 */
private function generateArchaeologistTtl($archaeologistUri, $archaeologistData)
{
    $ttl = "<$archaeologistUri> a excav:Archaeologist ;\n";
    
    if (!empty($archaeologistData['name'])) {
        $ttl .= "    foaf:name \"" . $archaeologistData['name'] . "\"^^xsd:literal ;\n";
    }
    
    if (!empty($archaeologistData['orcid'])) {
        // Format ORCID as full URL (like file uploads)
        $orcidUrl = "https://orcid.org/" . str_replace('https://orcid.org/', '', $archaeologistData['orcid']);
        $ttl .= "    foaf:account <$orcidUrl> ;\n";
    }
    
    if (!empty($archaeologistData['email'])) {
        // Handle multiple emails if provided
        $emails = is_array($archaeologistData['email']) ? $archaeologistData['email'] : [$archaeologistData['email']];
        foreach ($emails as $email) {
            $emailUrl = "mailto:" . str_replace('mailto:', '', $email);
            $ttl .= "    foaf:mbox <$emailUrl> ;\n";
        }
    }
    
    $ttl .= "    .\n\n";
    
    return $ttl;
}

/**
 * ENHANCED: Generate square TTL with proper coordinate structure
 */
private function generateSquareTtl($squareUri, $square)
{
    $ttl = "<$squareUri> a excav:Square ;\n";
    $ttl .= "    dct:identifier \"" . $square['square_id'] . "\"^^xsd:literal ;\n";
    
    // Use proper coordinate field names to match file uploads
    if (!empty($square['square_east_west'])) {
        $ttl .= "    geo:lat \"" . $square['square_east_west'] . "\"^^xsd:decimal ;\n";
    }
    
    if (!empty($square['square_north_south'])) {
        $ttl .= "    geo:long \"" . $square['square_north_south'] . "\"^^xsd:decimal ;\n";
    }
    
    $ttl .= "    .\n\n";
    
    return $ttl;
}


private function generateEncounterTtl($encounterUri, $encounter, $excavationUri, $itemUri)
{
    $ttl = "<$encounterUri> a excav:EncounterEvent;\n";
    
    if (!empty($encounter['encounter_date'])) {
        $ttl .= "    dct:date \"" . $encounter['encounter_date'] . "\"^^xsd:literal;\n";
    }
    
    $ttl .= "    excav:foundInExcavation <$excavationUri>;\n";
    error_log('Encounter URI: ' . $excavationUri, 3, OMEKA_PATH . '/logs/form.log');
    
    // Add link to the location if available
    if (isset($encounter['location_uri']) && $encounter['location_uri']) {
        $ttl .= "    excav:foundInLocation <" . $encounter['location_uri'] . ">;\n";
    }
    
    // Add link to selected context if available
    if (!empty($encounter['context_uri'])) {
        $ttl .= "    excav:foundInContext <" . $encounter['context_uri'] . ">;\n";
    }
    
    // Add link to the encountered object (arrowhead)
    if ($itemUri) {
        $ttl .= "    crmsci:O19_encountered_object <$itemUri>;\n";
    }
    
    if (!empty($encounter['encounter_depth'])) {
        $ttl .= "    dbo:depth \"" . $encounter['encounter_depth'] . "\"^^xsd:decimal;\n";
    }
    
    $ttl .= "    .\n\n";
    
    return $ttl;
}

/**
 * Create item set data for excavation
 */
private function createExcavationItemSetData($excavationIdentifier, $excavationData)
{
    $title = "Excavation $excavationIdentifier";
    $description = "Archaeological excavation";
    
    if (!empty($excavationData['site_name'])) {
        $description .= " at " . $excavationData['site_name'];
    }
    
    if (!empty($excavationData['location'])) {
        $description .= " - " . $excavationData['location'];
    }
    
    $itemSetData = [
        'dcterms:title' => [
            [
                'type' => 'literal',
                'property_id' => 1,
                '@value' => $title
            ]
        ],
        'dcterms:description' => [
            [
                'type' => 'literal',
                'property_id' => 4,
                '@value' => $description
            ]
        ],
        'dcterms:identifier' => [
            [
                'type' => 'literal',
                'property_id' => 10,
                '@value' => $excavationIdentifier
            ]
        ],
        'o:is_public' => true
    ];
    
    // Add creator if archaeologist is available
    if (!empty($excavationData['archaeologist']['name'])) {
        $itemSetData['dcterms:creator'] = [
            [
                'type' => 'literal',
                'property_id' => 7665,
                '@value' => $excavationData['archaeologist']['name']
            ]
        ];
    }
    
    return $itemSetData;
}



private function processArchaeologicalContextSelections($formData, $itemSetId, $baseUri)
{
    $linkedResources = [];
    $declarations = []; // Store resource declarations if needed
    
    error_log('=== PROCESSING CONTEXT SELECTIONS ===', 3, OMEKA_PATH . '/logs/context-debug.log');
    
    // Process selected square
    if (!empty($formData['selected_square'])) {
        $squareItemId = $formData['selected_square'];
        error_log("Processing selected square: $squareItemId", 3, OMEKA_PATH . '/logs/context-debug.log');
        
        // Get the real identifier from the Omeka item
        $realSquareId = $this->getRealIdentifierFromOmekaItem($squareItemId);
        if ($realSquareId) {
            // Create URI using the real identifier
            $squareUri = "$baseUri/square/$realSquareId";
            $linkedResources['excav:foundInSquare'] = $squareUri;
            
            error_log("Linked to square: $squareUri (real ID: $realSquareId)", 3, OMEKA_PATH . '/logs/context-debug.log');
        } else {
            error_log("Could not get real identifier for square item $squareItemId", 3, OMEKA_PATH . '/logs/context-debug.log');
        }
    }
    
    // Process selected context
    if (!empty($formData['selected_context'])) {
        $contextItemId = $formData['selected_context'];
        error_log("Processing selected context: $contextItemId", 3, OMEKA_PATH . '/logs/context-debug.log');
        
        $realContextId = $this->getRealIdentifierFromOmekaItem($contextItemId);
        if ($realContextId) {
            $contextUri = "$baseUri/context/$realContextId";
            $linkedResources['excav:foundInContext'] = $contextUri;
            
            error_log("Linked to context: $contextUri (real ID: $realContextId)", 3, OMEKA_PATH . '/logs/context-debug.log');
        } else {
            error_log("Could not get real identifier for context item $contextItemId", 3, OMEKA_PATH . '/logs/context-debug.log');
            
            // FALLBACK: Try to get context info directly and generate a reasonable identifier
            try {
                $contextItem = $this->api()->read('items', $contextItemId)->getContent();
                $contextTitle = $contextItem->displayTitle();
                
                // Extract identifier from title if possible
                if (preg_match('/\b(CV-\d+(?:-\d+)?)\b/', $contextTitle, $matches)) {
                    $fallbackContextId = $matches[1];
                    $contextUri = "$baseUri/context/$fallbackContextId";
                    $linkedResources['excav:foundInContext'] = $contextUri;
                    
                    error_log("Used fallback context ID from title: $fallbackContextId", 3, OMEKA_PATH . '/logs/context-debug.log');
                } else {
                    // Generate a basic context identifier
                    $fallbackContextId = "CTX-" . str_pad($contextItemId % 1000, 3, '0', STR_PAD_LEFT);
                    $contextUri = "$baseUri/context/$fallbackContextId";
                    $linkedResources['excav:foundInContext'] = $contextUri;
                    
                    error_log("Generated fallback context ID: $fallbackContextId", 3, OMEKA_PATH . '/logs/context-debug.log');
                }
            } catch (\Exception $e) {
                error_log("Error processing context item $contextItemId: " . $e->getMessage(), 3, OMEKA_PATH . '/logs/context-debug.log');
            }
        }
    }
    
    // Process selected SVU
    if (!empty($formData['selected_svu'])) {
        $svuItemId = $formData['selected_svu'];
        error_log("Processing selected SVU: $svuItemId", 3, OMEKA_PATH . '/logs/context-debug.log');
        
        $realSvuId = $this->getRealIdentifierFromOmekaItem($svuItemId);
        if ($realSvuId) {
            $svuUri = "$baseUri/svu/$realSvuId";
            $linkedResources['excav:foundInSVU'] = $svuUri;
            
            error_log("Linked to SVU: $svuUri (real ID: $realSvuId)", 3, OMEKA_PATH . '/logs/context-debug.log');
        } else {
            error_log("Could not get real identifier for SVU item $svuItemId", 3, OMEKA_PATH . '/logs/context-debug.log');
            
            // FALLBACK: Try to get SVU info directly and generate a reasonable identifier
            try {
                $svuItem = $this->api()->read('items', $svuItemId)->getContent();
                $svuTitle = $svuItem->displayTitle();
                
                // Extract identifier from title if possible
                if (preg_match('/\b(CV-\d+-\d+)\b/', $svuTitle, $matches)) {
                    $fallbackSvuId = $matches[1];
                    $svuUri = "$baseUri/svu/$fallbackSvuId";
                    $linkedResources['excav:foundInSVU'] = $svuUri;
                    
                    error_log("Used fallback SVU ID from title: $fallbackSvuId", 3, OMEKA_PATH . '/logs/context-debug.log');
                } else {
                    // Generate a basic SVU identifier
                    $fallbackSvuId = "SVU-" . str_pad($svuItemId % 1000, 3, '0', STR_PAD_LEFT);
                    $svuUri = "$baseUri/svu/$fallbackSvuId";
                    $linkedResources['excav:foundInSVU'] = $svuUri;
                    
                    error_log("Generated fallback SVU ID: $fallbackSvuId", 3, OMEKA_PATH . '/logs/context-debug.log');
                }
            } catch (\Exception $e) {
                error_log("Error processing SVU item $svuItemId: " . $e->getMessage(), 3, OMEKA_PATH . '/logs/context-debug.log');
            }
        }
    }
    
    error_log('Final linked resources: ' . print_r($linkedResources, true), 3, OMEKA_PATH . '/logs/context-debug.log');
    
    return [
        'references' => $linkedResources, 
        'declarations' => $declarations
    ];
}


private function processArrowheadFormData($formData, $itemSetId)
{
    // Debug log the incoming form data
    error_log('=== FORM PROCESSING DEBUG ===', 3, OMEKA_PATH . '/logs/form-debug.log');
    error_log('Form data received: ' . print_r($formData, true), 3, OMEKA_PATH . '/logs/form-debug.log');
    
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
    $excavationUri = "$baseUri";
    $encounterUri = "$baseUri/encounter/$arrowheadId";
    
    // Build TTL data
    $ttl = $this->getTtlPrefixes();
    
    // ENHANCED: Process selected archaeological context resources
    $contextResult = $this->processArchaeologicalContextSelections($formData, $itemSetId, $baseUri);
    $linkedResources = $contextResult['references'];
    $resourceDeclarations = $contextResult['declarations'];
    
    $ttl .= "<$arrowheadUri> a ah:Arrowhead, excav:Item;\n";
    $ttl .= "    dct:identifier \"$arrowheadId\"^^xsd:literal;\n";
    $ttl .= "    excav:foundInExcavation <$excavationUri>;\n";
    error_log("Arrowhead URI: $excavationUri", 3, OMEKA_PATH . '/logs/form.log');

    // FIXED: Create a consistent location URI based on the correct item set pattern
    $locationUri = $this->getRealLocationUriFromExcavation($itemSetId);
if ($locationUri) {
        $ttl .= "    excav:foundInLocation <$locationUri>;\n";
        error_log("✓ Added real location URI: $locationUri", 3, OMEKA_PATH . '/logs/form-debug.log');
    } else {
        error_log("⚠ No real location found - skipping location reference", 3, OMEKA_PATH . '/logs/form-debug.log');
    }
        error_log("Added consistent location URI: $locationUri", 3, OMEKA_PATH . '/logs/form-debug.log');
    
    // CRITICAL FIX: Add ALL context references to the arrowhead item
    foreach ($linkedResources as $property => $resourceUri) {
        $ttl .= "    $property <$resourceUri>;\n";
        error_log("Added reference: $property -> $resourceUri", 3, OMEKA_PATH . '/logs/form-debug.log');
    }

    // link to the encounter event
    $ttl .= "    crmsci:O19i_was_object_encountered_through <$encounterUri>;\n";
    
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
    
    // FIXED: Add elongation index with correct KOS namespace
    if (!empty($formData['elongation_index'])) {
        $ttl .= "    excav:elongationIndex <https://purl.org/megalod/kos/MegaLOD-IndexElongation/" . $formData['elongation_index'] . ">;\n";
    }
    
    // Add material
    if (!empty($formData['arrowhead_material'])) {
        $ttl .= "    crm:E57_Material <" . $formData['arrowhead_material'] . ">;\n";
    }
    
    // FIXED: Add shape with correct KOS namespace
    if (!empty($formData['arrowhead_shape'])) {
        $shapeMapping = [
            'triangle' => 'triangle',
            'lozenge-shaped' => 'losangular',
            'losangular' => 'losangular',
            'stemmed' => 'stemmed'
        ];
        
        $shapeSafe = isset($shapeMapping[$formData['arrowhead_shape']]) 
            ? $shapeMapping[$formData['arrowhead_shape']] 
            : strtolower(str_replace('-', '-', $formData['arrowhead_shape']));
            
        $ttl .= "    ah:shape <https://purl.org/megalod/kos/ah-shape/$shapeSafe>;\n";
    }
    
    // FIXED: Add variant with correct KOS namespace
    if (!empty($formData['arrowhead_variant'])) {
        $variantSafe = strtolower($formData['arrowhead_variant']);
        $ttl .= "    ah:variant <https://purl.org/megalod/kos/ah-variant/$variantSafe>;\n";
    }

    // Process measurements...
    $measurementBlocks = "";
    $processedMeasurements = [];

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
            
            if ($measurement === 'weight') {
                $ttl .= "    schema:weight <$measurementUri>;\n";
                $measurementBlocks .= "<$measurementUri> a excav:Weight;\n";
                $measurementBlocks .= "    schema:value \"" . $formData[$valueKey] . "\"^^xsd:decimal;\n";
                
                if (!empty($formData[$unitKey]) && !isset($processedMeasurements[$measurementUri])) {
                    $weightUnit = $formData[$unitKey];
                    $measurementBlocks .= "    schema:UnitCode <$weightUnit>;\n";
                    $processedMeasurements[$measurementUri] = true;
                }
                $measurementBlocks .= "    .\n\n";
            } else {
                $ttl .= "    schema:$property <$measurementUri>;\n";
                $measurementBlocks .= "<$measurementUri> a excav:TypometryValue;\n";
                $measurementBlocks .= "    schema:value \"" . $formData[$valueKey] . "\"^^xsd:decimal;\n";
                
                if (!empty($formData[$unitKey]) && !isset($processedMeasurements[$measurementUri])) {
                    $measurementBlocks .= "    schema:UnitCode <" . $formData[$unitKey] . ">;\n";
                    $processedMeasurements[$measurementUri] = true;
                }
                $measurementBlocks .= "    .\n\n";
            }
        }
    }

    // Add thickness, body length, base length processing...
    if (!empty($formData['thickness'])) {
        $thicknessUri = "$baseUri/typometry/$arrowheadId-thickness";
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
    
    // Add chipping information
    $hasChippingData = !empty($formData['chipping_mode']) || 
                       !empty($formData['chipping_amplitude']) || 
                       !empty($formData['chipping_direction']);
    
    if ($hasChippingData) {
        $ttl .= "    ah:hasChipping <$chippingUri>;\n";
    }
    
    // Add morphology reference
    $ttl .= "    ah:hasMorphology <$morphologyUri>;\n";

    // Process coordinates
    $coordinatesData = null;
    if (!empty($formData['x_coordinate']) && !empty($formData['y_coordinate'])) {
        $coordinatesUri = "$baseUri/coordinatesInSquare/" . substr($arrowheadId, 3);
        $ttl .= "    excav:hasCoordinatesInSquare <$coordinatesUri>;\n";
    
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

    // Add coordinates block if present
    if ($coordinatesData) {
        $ttl .= "<{$coordinatesData['uri']}> a excav:Coordinates;\n";
        $ttl .= "    schema:value \"{$coordinatesData['x']} {$coordinatesData['x_unit']}\"^^xsd:literal;\n";
        $ttl .= "    schema:value \"{$coordinatesData['y']} {$coordinatesData['y_unit']}\"^^xsd:literal;\n";
        
        if ($coordinatesData['z']) {
            $ttl .= "    schema:value \"{$coordinatesData['z']} {$coordinatesData['z_unit']}\"^^xsd:literal;\n";
        }
        
        $ttl .= "    .\n\n";
    }
    
    // Add morphology
    $ttl .= "<$morphologyUri> a ah:Morphology;\n";
    
    if (!empty($formData['point_definition'])) {
        $value = (stripos($formData['point_definition'], 'true') !== false) ? "true" : "false";
        $ttl .= "    ah:point \"$value\"^^xsd:boolean;\n";
    }
    
    if (!empty($formData['body_symmetry'])) {
        $value = (stripos($formData['body_symmetry'], 'true') !== false) ? "true" : "false";
        $ttl .= "    ah:body \"$value\"^^xsd:boolean;\n";
    }
    
    // FIXED: Base with correct KOS namespace
    if (!empty($formData['arrowhead_base'])) {
        $baseSafe = strtolower($formData['arrowhead_base']);
        $ttl .= "    ah:base <https://purl.org/megalod/kos/ah-base/$baseSafe>;\n";
    }
    
    $ttl .= "    .\n\n";
    
    // Add chipping details if necessary
    if ($hasChippingData) {
        $ttl .= "<$chippingUri> a ah:Chipping;\n";
        
        // FIXED: All chipping properties with correct KOS namespace
        if (!empty($formData['chipping_mode'])) {
            $modeSafe = strtolower(str_replace('-', '-', $formData['chipping_mode']));
            $ttl .= "    ah:chippingMode <https://purl.org/megalod/kos/ah-chippingMode/$modeSafe>;\n";
        }
        
        if (!empty($formData['chipping_amplitude'])) {
            $value = (stripos($formData['chipping_amplitude'], 'true') !== false) ? "true" : "false";
            $ttl .= "    ah:chippingAmplitude \"$value\"^^xsd:boolean;\n";
        }
        
        if (!empty($formData['chipping_direction'])) {
            $directionSafe = strtolower($formData['chipping_direction']);
            $ttl .= "    ah:chippingDirection <https://purl.org/megalod/kos/ah-chippingDirection/$directionSafe>;\n";
        }
        
        if (!empty($formData['chipping_orientation'])) {
            $value = (stripos($formData['chipping_orientation'], 'true') !== false) ? "true" : "false";
            $ttl .= "    ah:chippingOrientation \"$value\"^^xsd:boolean;\n";
        }
        
        if (!empty($formData['chipping_delineation'])) {
            $delineationSafe = strtolower($formData['chipping_delineation']);
            $ttl .= "    ah:chippingDelineation <https://purl.org/megalod/kos/ah-chippingDelineation/$delineationSafe>;\n";
        }
        
        for ($i = 1; $i <= 3; $i++) {
            $lateralKey = "chipping_location_lateral_$i";
            if (!empty($formData[$lateralKey])) {
                $locationSafe = strtolower($formData[$lateralKey]);
                $ttl .= "    ah:chippingLocationSide <https://purl.org/megalod/kos/ah-chippingLocation/$locationSafe>;\n";
            }
        }
        
        for ($i = 1; $i <= 3; $i++) {
            $transversalKey = "chipping_location_transversal_$i";
            if (!empty($formData[$transversalKey])) {
                $locationSafe = strtolower($formData[$transversalKey]);
                $ttl .= "    ah:chippingLocationTransversal <https://purl.org/megalod/kos/ah-chippingLocation/$locationSafe>;\n";
            }
        }
        
        if (!empty($formData['chipping_shape'])) {
            $shapeSafe = strtolower($formData['chipping_shape']);
            $ttl .= "    ah:chippingShape <https://purl.org/megalod/kos/ah-chippingShape/$shapeSafe>;\n";
        }
        
        $ttl .= "    .\n\n";
    }
    
    // FIXED: Add encounter event with ALL context references
    $ttl .= "<$encounterUri> a excav:EncounterEvent;\n";
    $ttl .= "    dct:date \"" . date('Y-m-d') . "\"^^xsd:literal;\n";
    $ttl .= "    crmsci:O19_encountered_object <$arrowheadUri>;\n";
    $ttl .= "    excav:foundInExcavation <$excavationUri>;\n"; 

    // CRITICAL FIX: Add ALL selected context references to encounter event
    foreach ($linkedResources as $property => $resourceUri) {
        $ttl .= "    $property <$resourceUri>;\n";
        error_log("Added $property to encounter event: $resourceUri", 3, OMEKA_PATH . '/logs/form-debug.log');
    }
    
    $ttl .= "    .\n\n";
    
    // Add all measurement resource blocks
    $ttl .= $measurementBlocks;
    
    // FIXED: Add proper location declaration to satisfy SHACL
    if (strpos($ttl, 'excav:foundInLocation') !== false) {
        $ttl .= "<$locationUri> a excav:Location;\n";
        $ttl .= "    rdfs:label \"Excavation Location\"^^xsd:string;\n";
        $ttl .= "    .\n\n";
        
        error_log("Added location type declaration for $locationUri", 3, OMEKA_PATH . '/logs/ttl-fixes.log');
    }
    
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
        error_log('Transformed arrowhead data: ' . print_r($arrowheadData, true), 3, OMEKA_PATH . '/logs/property-debug.log');

        
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
    'prompt_54' => 'images',                  // Images (empty in your case)
    'prompt_55' => 'arrowhead_annotation',    // Observations/annotations
    'prompt_56' => 'condition_state',         // Complete/Broken
    'prompt_57' => 'weight',                  // Weight value
    'prompt_58' => 'weight_unit',             // Weight unit
    'prompt_59' => 'height',                  // Height value
    'prompt_60' => 'height_unit',             // Height unit
    'prompt_61' => 'width',                   // Width value
    'prompt_62' => 'width_unit',              // Width unit
    'prompt_63' => 'thickness',               // Thickness value
    'prompt_64' => 'thickness_unit',          // Thickness unit
    'prompt_65' => 'arrowhead_type',          // Elongate/Short
    'prompt_66' => 'elongation_index',        // Medium/Elongated/Short
    'prompt_67' => 'gps_latitude',            // GPS latitude (empty in your case)
    'prompt_68' => 'gps_longitude',           // GPS longitude (empty in your case)
    'prompt_69' => 'arrowhead_variant',       // Flat/Raised/Thick
    'prompt_70' => 'arrowhead_shape',         // Triangle/Losangular/Stemmed
    'prompt_71' => 'point_definition',        // Sharp/Fractured
    'prompt_72' => 'body_symmetry',           // Symmetrical/Non-symmetrical
    'prompt_73' => 'arrowhead_base',          // Base type
    'prompt_74' => 'body_length',             // Body length value
    'prompt_75' => 'body_length_unit',        // Body length unit
    'prompt_76' => 'base_length',             // Base length value
    'prompt_77' => 'base_length_unit',        // Base length unit
    
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
    'prompt_93' => 'arrowhead_material',      // Material
    'prompt_94' => 'x_coordinate_unit',       // X coordinate unit
    'prompt_95' => 'y_coordinate_unit',       // Y coordinate unit  
    'prompt_96' => 'z_coordinate_unit',       // Z coordinate unit
];
    
    // Process the mapping
// In transformCollectingFormToArrowheadData method, add this logic:

foreach ($fieldMappings as $collectingField => $arrowheadField) {
    if (isset($formData[$collectingField]) && !empty($formData[$collectingField])) {
        $value = $formData[$collectingField];
        
        // Enhanced boolean processing
        if (strpos($value, 'True') === 0 || strpos($value, 'true') === 0) {
            $arrowheadData[$arrowheadField] = 'true';
        } elseif (strpos($value, 'False') === 0 || strpos($value, 'false') === 0) {
            $arrowheadData[$arrowheadField] = 'false';
        } else {
            // Clean up parenthetical explanations
            // "True (Elongate)" becomes "true"
            // "Raised" stays "Raised"
            $cleanValue = preg_replace('/\s*\([^)]*\)/', '', $value);
            $arrowheadData[$arrowheadField] = trim($cleanValue);
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

    $itemSetId = isset($formData['item_set_id']) ? $formData['item_set_id'] : null;
    
    // If we have an item set ID, try to find and add the location from the excavation
    if ($itemSetId) {
        try {
            // FIXED: Use the item set ID directly for the location URI
            $locationUri = $this->getExcavationLocationUri(null, $itemSetId);
            
            if ($locationUri) {
                error_log("Using location for item set $itemSetId: $locationUri", 3, OMEKA_PATH . '/logs/location-debug.log');
                $arrowheadData['location'] = $locationUri;
            }
        } catch (\Exception $e) {
            error_log('Error setting excavation location: ' . $e->getMessage(), 3, OMEKA_PATH . '/logs/location-debug.log');
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

private function getExcavationLocationUri($excavationId, $itemSetId = null) {
    // FIXED: Use itemSetId if available, otherwise use excavationId
    $baseId = $itemSetId ?: $excavationId;
    return "https://purl.org/megalod/$baseId/location/excavation-location";
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

    


private function uploadTtlData(string $ttlData, ?int $itemSetId = null): string {
    error_log("=== UPLOAD TTL DATA DEBUG START ===", 3, OMEKA_PATH . '/logs/upload-debug.log');
    error_log("Item Set ID: " . ($itemSetId ?: 'none'), 3, OMEKA_PATH . '/logs/upload-debug.log');
    error_log("TTL Data Length: " . strlen($ttlData), 3, OMEKA_PATH . '/logs/upload-debug.log');
    error_log("TTL Data Sample (first 1000 chars): " . substr($ttlData, 0, 1000), 3, OMEKA_PATH . '/logs/upload-debug.log');
    error_log("itemsetid: " . ($itemSetId ?: 'none'), 3, OMEKA_PATH . '/logs/hkjfhkj-debug.log');
    // Set the current processing context
    $this->currentProcessingItemSetId = $itemSetId;
    
    try {
        // Check if this is excavation data
        error_log('=== CHECKING IF EXCAVATION DATA ===', 3, OMEKA_PATH . '/logs/upload-debug.log');
        $isExcavation = false;
        $excavationIdentifier = "0"; // Default to "0" graph

        try {
            $this->validateUploadType($ttlData, 'excavation');
            error_log('✓ Excavation validation passed', 3, OMEKA_PATH . '/logs/upload-debug.log');
            $isExcavation = true;
            
            // Extract excavation identifier for graph organization
            $extractedId = $this->extractExcavationIdentifier($ttlData);
            if ($extractedId) {
                $excavationIdentifier = $extractedId;
                error_log('✓ Extracted excavation identifier: ' . $excavationIdentifier, 3, OMEKA_PATH . '/logs/upload-debug.log');
            } else {
                error_log('⚠ Could not extract excavation identifier', 3, OMEKA_PATH . '/logs/upload-debug.log');
            }
        } catch (\Exception $e) {
            error_log('❌ Excavation validation failed: ' . $e->getMessage(), 3, OMEKA_PATH . '/logs/upload-debug.log');
            
            // Check if this item belongs to an excavation item set
            if ($itemSetId) {
                error_log('Item set ID provided: ' . $itemSetId, 3, OMEKA_PATH . '/logs/upload-debug.log');
                $excavationId = $this->getExcavationIdentifierFromItemSet($itemSetId);
                if ($excavationId) {
                    $excavationIdentifier = $excavationId;
                    error_log('Using excavation ID from item set: ' . $excavationIdentifier, 3, OMEKA_PATH . '/logs/upload-debug.log');
                }
            }
        }

        error_log('Final determination - isExcavation: ' . ($isExcavation ? 'true' : 'false'), 3, OMEKA_PATH . '/logs/upload-debug.log');
        error_log('Excavation identifier: ' . $excavationIdentifier, 3, OMEKA_PATH . '/logs/upload-debug.log');


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
            //log new ttlData
            
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
                    
                    error_log('Successfully created single item set with ID: ' . $itemSetId, 3, OMEKA_PATH . '/logs/kkkkkkkkkkklllllll-debug.log');
                    
                    // Update the processing context with the new item set ID
                    $this->currentProcessingItemSetId = $itemSetId;
                    
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
        error_log('New TTL data for excavation: ' . $ttlData, 3, OMEKA_PATH . '/logs/ttlttl-debug.log');

        error_log('is excavation: ' . ($isExcavation ? 'true' : 'false'), 3, OMEKA_PATH . '/logs/excavation-debug.log');

        // deal with encounter validation for arrowheads
        $isArrowhead = strpos($ttlData, 'ah:Arrowhead') !== false || strpos($ttlData, 'excav:Item') !== false;
        error_log('is arrowhead: ' . ($isArrowhead ? 'true' : 'false'), 3, OMEKA_PATH . '/logs/arrowhead-debug.log');
        error_log('isitemSetId: ' . ($itemSetId ? $itemSetId : 'none'), 3, OMEKA_PATH . '/logs/arrowhead-debug.log');
        error_log('isExcavation: ' . ($isExcavation ? 'true' : 'false'), 3, OMEKA_PATH . '/logs/arrowhead-debug.log');
        if ($isArrowhead && $itemSetId) {
            error_log('=== APPLYING ENCOUNTER VALIDATION FOR ARROWHEAD ===', 3, OMEKA_PATH . '/logs/encounter-validation.log');
            error_log('Item Set ID: ' . $itemSetId, 3, OMEKA_PATH . '/logs/encounter-validation.log');
            // 1. Extract arrowhead context references from TTL
            $arrowheadContext = $this->extractArrowheadContextFromTtl($ttlData);
            error_log('Extracted context: ' . print_r($arrowheadContext, true), 3, OMEKA_PATH . '/logs/encounter-validation.log');
            
            // 2. Validate context relationships exist in item set
            $validationResult = $this->validateContextRelationships($arrowheadContext, $itemSetId);
            
            if (!$validationResult['valid']) {
                // Return validation error immediately
                $errorDetails = "\n\nValidation Details:\n" . json_encode($validationResult['details'], JSON_PRETTY_PRINT);
                return 'Validation Error: ' . $validationResult['error'] . $errorDetails;
            }
            
            error_log('✓ Context validation passed', 3, OMEKA_PATH . '/logs/encounter-validation.log');
            
            // 3. Find or create encounter event
            $encounterEvent = $this->findOrCreateEncounterEvent($arrowheadContext, $itemSetId);
            error_log('Encounter event: ' . print_r($encounterEvent, true), 3, OMEKA_PATH . '/logs/encounter-validation.log');
            
            // 4. Update TTL with encounter event reference
            $ttlData = $this->addEncounterEventToTtl($ttlData, $encounterEvent, $itemSetId);
            error_log('✓ Enhanced TTL with encounter event', 3, OMEKA_PATH . '/logs/encounter-validation.log');
            error_log('Final TTL after encounter event: ' . $ttlData, 3, OMEKA_PATH . '/logs/encounter-ttl-final.log');
        }


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
            error_log("Updated title for item $itemId: '$title'", 3, OMEKA_PATH . '/logs/excavation-debug.log');
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
                          count($createdItems) . ' items with updated titles and proper resource links within excavation context.';
                }
            } else {
                return 'Data uploaded to GraphDB, but Omeka S errors: ' . 
                      implode('; ', $omekaResponse['errors']);
            }
        } else {
            return 'Failed to upload data to GraphDB: ' . $graphDbResult;
        }
        
    } finally {
        // Clear the processing context - this will always execute
        $this->currentProcessingItemSetId = null;
        error_log("Cleared processing context", 3, OMEKA_PATH . '/logs/context-debug.log');
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



private function extractExcavationIdentifier(string $ttlData): ?string {
    error_log('Extracting excavation identifier from TTL', 3, OMEKA_PATH . '/logs/excavation-identifier.log');
    
    // Pattern 1: Direct dct:identifier pattern from your TTL
    if (preg_match('/dct:identifier\s+"([^"]+)"\^\^xsd:literal/', $ttlData, $matches)) {
        error_log('Found identifier via dct:identifier: ' . $matches[1], 3, OMEKA_PATH . '/logs/excavation-identifier.log');
        return $matches[1];
    }
    
    // Pattern 2: Alternative dcterms:identifier
    if (preg_match('/dcterms:identifier\s+"([^"]+)"/', $ttlData, $matches)) {
        error_log('Found identifier via dcterms:identifier: ' . $matches[1], 3, OMEKA_PATH . '/logs/excavation-identifier.log');
        return $matches[1];
    }
    
    // Pattern 3: Extract from excavation URI pattern
    if (preg_match('/<https:\/\/purl\.org\/megalod\/([^\/]+)\/excavation\/([^>]+)>/', $ttlData, $matches)) {
        $identifier = $matches[2]; // This should be "EXC-001"
        error_log('Found identifier from URI pattern: ' . $identifier, 3, OMEKA_PATH . '/logs/excavation-identifier.log');
        return $identifier;
    }
    
    // Pattern 4: Look for any EXC-XXX pattern in the file
    if (preg_match('/EXC-\d+/', $ttlData, $matches)) {
        error_log('Found EXC pattern: ' . $matches[0], 3, OMEKA_PATH . '/logs/excavation-identifier.log');
        return $matches[0];
    }
    
    error_log('No excavation identifier found in TTL', 3, OMEKA_PATH . '/logs/excavation-identifier.log');
    return null;
}


private function validateUploadType(string $ttlData, ?string $uploadType): void
{
    if (!$uploadType) {
        return; // No upload type specified, skip validation
    }

    error_log('Validating upload type: ' . $uploadType, 3, OMEKA_PATH . '/logs/validation.log');
    error_log('TTL data sample: ' . substr($ttlData, 0, 500), 3, OMEKA_PATH . '/logs/validation.log');
    
    // UPDATED patterns to match your TTL file
    $excavationPatterns = [
        'a excav:Excavation',
        'excav:Excavation',
        'crmarchaeo:A9_Archaeological_Excavation',
        'a crmarchaeo:A9_Archaeological_Excavation',
        'excav:hasPersonInCharge',
        'excav:hasSquare',
        'excav:hasContext'
    ];
    
    $arrowheadPatterns = [
        'a ah:Arrowhead',
        'ah:Arrowhead',
        'a excav:Item',
        'excav:Item',
        'ah:shape',
        'ah:variant',
        'ah:hasMorphology',
        'ah:hasChipping'
    ];
    
    $isExcavation = false;
    foreach ($excavationPatterns as $pattern) {
        if (strpos($ttlData, $pattern) !== false) {
            $isExcavation = true;
            error_log("Found excavation pattern: $pattern", 3, OMEKA_PATH . '/logs/validation.log');
            break;
        }
    }
    
    $isArrowhead = false;
    foreach ($arrowheadPatterns as $pattern) {
        if (strpos($ttlData, $pattern) !== false) {
            $isArrowhead = true;
            error_log("Found arrowhead pattern: $pattern", 3, OMEKA_PATH . '/logs/validation.log');
            break;
        }
    }
    
    error_log("Validation results - isExcavation: " . ($isExcavation ? 'true' : 'false') . 
              ", isArrowhead: " . ($isArrowhead ? 'true' : 'false'), 3, OMEKA_PATH . '/logs/validation.log');
    
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
        error_log('subject type: ' . $subjectType, 3, OMEKA_PATH . '/logs/subject.log');
        // Process based on subject type
        switch ($subjectType) {
            case 'arrowhead':
            case 'item':
                $this->processArrowheadData($rdfData, $subject, $itemData);
                break;
            case 'excavation':
                error_log('Processing excavation data for subject: ' . $subject, 3, OMEKA_PATH . '/logs/abcd.log');
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



private function identifyMainSubjects($rdfData, $itemSetId = null) {
    $subjects = [];
    
    error_log('=== IDENTIFYING MAIN SUBJECTS ===', 3, OMEKA_PATH . '/logs/main-subjects.log');
    error_log('Total RDF subjects: ' . count($rdfData), 3, OMEKA_PATH . '/logs/main-subjects.log');
    
    // UPDATED patterns to match both original and normalized URIs
    $mainSubjectTypes = [
        // Original namespace patterns
        'https://purl.org/megalod/ms/ah/Arrowhead' => 'arrowhead',
        'https://purl.org/megalod/ms/excavation/Item' => 'item',
        'https://purl.org/megalod/ms/excavation/Excavation' => 'excavation',
        'https://purl.org/megalod/ms/excavation/Context' => 'context',
        'https://purl.org/megalod/ms/excavation/StratigraphicVolumeUnit' => 'svu',
        'https://purl.org/megalod/ms/excavation/Square' => 'square',
        'excav:Excavation' => 'excavation',
        'excav:Context' => 'context',
        'ah:Arrowhead' => 'arrowhead',
        'excav:Item' => 'item',
        'excav:StratigraphicVolumeUnit' => 'svu',
        'excav:Square' => 'square',
    ];
    
    // DYNAMIC: Add normalized patterns based on itemSetId if available
    if ($itemSetId) {
        $normalizedPatterns = [
            "https://purl.org/megalod/$itemSetId/ah/Arrowhead" => 'arrowhead',
            "https://purl.org/megalod/$itemSetId/excavation/Item" => 'item', 
            "https://purl.org/megalod/$itemSetId/excavation/Excavation" => 'excavation',
            "https://purl.org/megalod/$itemSetId/excavation/Context" => 'context',
            "https://purl.org/megalod/$itemSetId/excavation/StratigraphicVolumeUnit" => 'svu',
            "https://purl.org/megalod/$itemSetId/excavation/Square" => 'square',
        ];
        
        // Merge normalized patterns
        $mainSubjectTypes = array_merge($mainSubjectTypes, $normalizedPatterns);
        
        error_log('Added normalized patterns for itemSetId: ' . $itemSetId, 3, OMEKA_PATH . '/logs/main-subjects.log');
        error_log('Normalized patterns: ' . print_r($normalizedPatterns, true), 3, OMEKA_PATH . '/logs/main-subjects.log');
    }
    
    // Define the excluded subject types to prevent creating empty objects
    $excludedTypes = [
        'https://purl.org/megalod/ms/excavation/Location',
        'https://purl.org/megalod/ms/excavation/GPSCoordinates',
        'http://www.ontologydesignpatterns.org/ont/dul/DUL.owl#Location',
        'http://dbpedia.org/ontology/Location',
        'excav:Location',
        'excav:GPSCoordinates',
        'https://purl.org/megalod/ms/excavation/Archaeologist',
        'excav:Archaeologist',
        'https://purl.org/megalod/ms/excavation/TimeLine',
        'https://purl.org/megalod/ms/excavation/Instant',
        'excav:TimeLine',
        'excav:Instant',
        'http://dbpedia.org/ontology/District',
        'http://dbpedia.org/ontology/Parish',
    ];
    
    // Add dynamic excluded types based on itemSetId
    if ($itemSetId) {
        $excludedTypes = array_merge($excludedTypes, [
            "https://purl.org/megalod/$itemSetId/excavation/Location",
            "https://purl.org/megalod/$itemSetId/excavation/GPSCoordinates", 
            "https://purl.org/megalod/$itemSetId/excavation/Archaeologist",
            "https://purl.org/megalod/$itemSetId/excavation/TimeLine",
            "https://purl.org/megalod/$itemSetId/excavation/Instant",
        ]);
    }
    
    // CRITICAL FIX: Determine if this is a complete excavation upload vs adding items to existing excavation
    $hasExcavationInData = false;
    $hasArrowheadsInData = false;
    
    // First pass: check what types of data we have
    foreach ($rdfData as $subject => $predicates) {
        if (isset($predicates['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'])) {
            foreach ($predicates['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'] as $typeObj) {
                if ($typeObj['type'] === 'uri') {
                    // Check for excavation
                    if ($typeObj['value'] === 'https://purl.org/megalod/ms/excavation/Excavation' ||
                        $typeObj['value'] === 'excav:Excavation' ||
                        ($itemSetId && $typeObj['value'] === "https://purl.org/megalod/$itemSetId/excavation/Excavation")) {
                        $hasExcavationInData = true;
                    }
                    
                    // Check for arrowheads
                    if ($typeObj['value'] === 'https://purl.org/megalod/ms/ah/Arrowhead' ||
                        $typeObj['value'] === 'ah:Arrowhead' ||
                        ($itemSetId && $typeObj['value'] === "https://purl.org/megalod/$itemSetId/ah/Arrowhead")) {
                        $hasArrowheadsInData = true;
                    }
                }
            }
        }
    }
    
    $isCompleteExcavationUpload = $hasExcavationInData && !$hasArrowheadsInData;
    $isArrowheadOnlyUpload = $hasArrowheadsInData && !$hasExcavationInData;
    
    error_log("Data analysis: hasExcavation=$hasExcavationInData, hasArrowheads=$hasArrowheadsInData", 3, OMEKA_PATH . '/logs/main-subjects.log');
    error_log("Upload type: isCompleteExcavation=$isCompleteExcavationUpload, isArrowheadOnly=$isArrowheadOnlyUpload", 3, OMEKA_PATH . '/logs/main-subjects.log');

    // Find arrowhead/item subjects first
    $arrowheadSubjects = [];
    foreach ($rdfData as $subject => $predicates) {
        if (isset($predicates['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'])) {
            foreach ($predicates['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'] as $typeObj) {
                if ($typeObj['type'] === 'uri') {
                    error_log("Found type for subject $subject: " . $typeObj['value'], 3, OMEKA_PATH . '/logs/main-subjects.log');
                    
                    // Check for arrowhead or item type
                    if ($typeObj['value'] === 'https://purl.org/megalod/ms/ah/Arrowhead' ||
                        $typeObj['value'] === 'https://purl.org/megalod/ms/excavation/Item' ||
                        $typeObj['value'] === 'ah:Arrowhead' ||
                        $typeObj['value'] === 'excav:Item' ||
                        ($itemSetId && ($typeObj['value'] === "https://purl.org/megalod/$itemSetId/ah/Arrowhead" ||
                                       $typeObj['value'] === "https://purl.org/megalod/$itemSetId/excavation/Item"))) {
                        
                        $arrowheadSubjects[$subject] = ($typeObj['value'] === 'https://purl.org/megalod/ms/ah/Arrowhead' || 
                                                      $typeObj['value'] === 'ah:Arrowhead' ||
                                                      ($itemSetId && $typeObj['value'] === "https://purl.org/megalod/$itemSetId/ah/Arrowhead")) 
                            ? 'arrowhead' : 'item';
                        
                        error_log("✓ Found arrowhead/item subject: $subject", 3, OMEKA_PATH . '/logs/main-subjects.log');
                    }
                    
                    // UPDATED LOGIC: Only exclude excavation subjects if this is arrowhead-only upload to existing item set
                    if ($itemSetId && $isArrowheadOnlyUpload &&
                        ($typeObj['value'] === 'https://purl.org/megalod/ms/excavation/Excavation' ||
                         $typeObj['value'] === 'excav:Excavation' ||
                         $typeObj['value'] === "https://purl.org/megalod/$itemSetId/excavation/Excavation")) {
                        
                        error_log("⚠ EXCLUDING excavation subject in arrowhead-only upload to item set: $subject", 3, OMEKA_PATH . '/logs/main-subjects.log');
                        $subjects[$subject] = 'excluded';
                    }
                }
            }
        }
    }

    // If this is arrowhead-only upload to existing item set, ONLY return arrowheads
    if ($itemSetId && $isArrowheadOnlyUpload && !empty($arrowheadSubjects)) {
        error_log('Arrowhead-only upload detected - returning only ' . count($arrowheadSubjects) . ' arrowhead subjects', 3, OMEKA_PATH . '/logs/main-subjects.log');
        return $arrowheadSubjects;
    }

    // For complete excavation uploads OR initial excavation creation, process all valid subjects
    foreach ($rdfData as $subject => $predicates) {
        // Skip subjects that were already marked as excluded
        if (isset($subjects[$subject]) && $subjects[$subject] === 'excluded') {
            continue;
        }

        error_log("Checking subject: $subject", 3, OMEKA_PATH . '/logs/main-subjects.log');
        
        if (isset($predicates['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'])) {
            foreach ($predicates['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'] as $typeObj) {
                error_log("  Found type: " . $typeObj['value'], 3, OMEKA_PATH . '/logs/main-subjects.log');
                
                // Skip encounter events and other auxiliary types
                if ($typeObj['type'] === 'uri' && 
                    $typeObj['value'] !== 'https://purl.org/megalod/ms/excavation/EncounterEvent' &&
                    $typeObj['value'] !== 'https://purl.org/megalod/ms/ah/Morphology' &&
                    $typeObj['value'] !== 'https://purl.org/megalod/ms/ah/Chipping' &&
                    $typeObj['value'] !== 'https://purl.org/megalod/ms/excavation/TypometryValue' &&
                    $typeObj['value'] !== 'https://purl.org/megalod/ms/excavation/Weight' &&
                    $typeObj['value'] !== 'https://purl.org/megalod/ms/excavation/Coordinates' &&
                    !in_array($typeObj['value'], $excludedTypes) &&  // Check against the excluded types
                    isset($mainSubjectTypes[$typeObj['value']])) {
                    
                    // UPDATED LOGIC: Only skip context/square/svu creation if this is arrowhead-only upload
                    if ($itemSetId && $isArrowheadOnlyUpload) {
                        $subjectType = $mainSubjectTypes[$typeObj['value']];
                        if (in_array($subjectType, ['context', 'svu', 'square', 'excavation'])) {
                            error_log("  ⚠ Skipping subject type '$subjectType' in arrowhead-only upload", 3, OMEKA_PATH . '/logs/main-subjects.log');
                            continue;
                        }
                    }
                    
                    $subjects[$subject] = $mainSubjectTypes[$typeObj['value']];
                    error_log("  ✓ Added as main subject: {$mainSubjectTypes[$typeObj['value']]}", 3, OMEKA_PATH . '/logs/main-subjects.log');
                    break; // Found the type, move to next subject
                }
                
                // If it's an excluded type, mark it to prevent processing in the fallback
                if (in_array($typeObj['value'], $excludedTypes)) {
                    $subjects[$subject] = 'excluded';
                    error_log("  ✓ Marked as excluded auxiliary type: {$typeObj['value']}", 3, OMEKA_PATH . '/logs/main-subjects.log');
                    break;
                }
            }
        }
    }
    
    // CRITICAL FIX: Only look for subjects with identifiers if we found NO proper subjects
    $foundSubjects = array_filter($subjects, function($type) { 
        return $type !== 'excluded'; 
    });
    
    if (count($foundSubjects) === 0) {
        error_log('No typed subjects found, looking for identifiers...', 3, OMEKA_PATH . '/logs/main-subjects.log');
        foreach ($rdfData as $subject => $predicates) {
            // Skip if we already identified this subject (even as excluded)
            if (isset($subjects[$subject])) {
                continue;
            }
            
            if (isset($predicates['http://purl.org/dc/terms/identifier'])) {
                // Additional check: Try to determine the entity type from URI pattern
                $isAuxiliary = false;
                
                // Check URI patterns that suggest auxiliary entities
                if (strpos($subject, '/location/') !== false || 
                    strpos($subject, '/gps/') !== false ||
                    strpos($subject, '/Timeline/') !== false ||
                    strpos($subject, '/archaeologist/') !== false) {
                    $isAuxiliary = true;
                    error_log("Skipping auxiliary entity with identifier: $subject", 3, OMEKA_PATH . '/logs/main-subjects.log');
                }
                
                // UPDATED: Only skip excavation/context/svu/square in arrowhead-only uploads
                if ($itemSetId && $isArrowheadOnlyUpload) {
                    if (strpos($subject, '/square/') !== false ||
                        strpos($subject, '/context/') !== false ||
                        strpos($subject, '/svu/') !== false) {
                        $isAuxiliary = true;
                        error_log("Skipping supporting entity in arrowhead-only upload: $subject", 3, OMEKA_PATH . '/logs/main-subjects.log');
                    }
                    
                    // Check if this is an excavation URI
                    foreach ($predicates['http://purl.org/dc/terms/identifier'] as $idObj) {
                        if ($idObj['type'] === 'literal' && strpos($idObj['value'], 'EXC-') === 0) {
                            $isAuxiliary = true;
                            error_log("Skipping excavation entity with identifier {$idObj['value']} in arrowhead-only upload: $subject", 3, OMEKA_PATH . '/logs/main-subjects.log');
                            break;
                        }
                    }
                }
                
                if (!$isAuxiliary) {
                    $subjects[$subject] = 'unknown';
                    error_log("Added subject with identifier: $subject", 3, OMEKA_PATH . '/logs/main-subjects.log');
                }
            }
        }
    }
    
    // Remove excluded subjects from the result
    $subjects = array_filter($subjects, function($type) {
        return $type !== 'excluded';
    });
    
    // If we're in an arrowhead-only upload context, prioritize items over other types
    if ($itemSetId && $isArrowheadOnlyUpload && !empty($subjects)) {
        $itemSubjects = array_filter($subjects, function($type) {
            return in_array($type, ['arrowhead', 'item']);
        });
        
        if (!empty($itemSubjects)) {
            error_log('Found ' . count($itemSubjects) . ' item subjects for arrowhead-only upload to item set ' . $itemSetId, 3, OMEKA_PATH . '/logs/main-subjects.log');
            return $itemSubjects;
        }
    }
    
    error_log('Final subjects identified: ' . print_r($subjects, true), 3, OMEKA_PATH . '/logs/main-subjects.log');
    
    return $subjects;
}
/**
 * Extract the identifier from a subject
 */
private function extractIdentifier($rdfData, $subject) {
    if (isset($rdfData[$subject]['http://purl.org/dc/terms/identifier'])) {
        foreach ($rdfData[$subject]['http://purl.org/dc/terms/identifier'] as $idObj) {
            if ($idObj['type'] === 'literal') {
                return $idObj['value'];
            }
        }
    }
    return null;
}







/**
 * UPDATED: Main processArrowheadData method with enhanced coordinate extraction
 */
private function processArrowheadData($rdfData, $subject, &$itemData) {
    error_log('=== ENHANCED ARROWHEAD PROCESSING ===', 3, OMEKA_PATH . '/logs/arrowhead-enhanced.log');
    error_log('Processing arrowhead data for subject: ' . $subject, 3, OMEKA_PATH . '/logs/arrowhead-enhanced.log');
    
    // Current item set context
    $currentItemSetId = $this->getCurrentItemSetContext();
    
    // 1. DIRECT ARROWHEAD PROPERTIES - Extract all direct properties first
    $this->extractDirectArrowheadProperties($rdfData, $subject, $itemData, $currentItemSetId);
    
    // 2. MEASUREMENTS - Extract all measurement values with units
    $this->extractAllMeasurements($rdfData, $subject, $itemData, $currentItemSetId);
    
    // 3. MORPHOLOGY DATA - Extract complete morphology information
    $this->extractCompleteMorphologyData($rdfData, $subject, $itemData, $currentItemSetId);
    
    // 4. CHIPPING DATA - Extract complete chipping information  
    $this->extractCompleteChippingData($rdfData, $subject, $itemData, $currentItemSetId);
    
    // 5. COORDINATES - Extract coordinate information (ENHANCED)
    $this->extractCoordinateDataEnhanced($rdfData, $subject, $itemData, $currentItemSetId);
    
    // 6. ENCOUNTER EVENT - Extract encounter event details (ENHANCED)
    $this->extractEncounterEventData($rdfData, $subject, $itemData, $currentItemSetId);
    
    // 7. ARCHAEOLOGICAL CONTEXT - Extract excavation, location, square references (ENHANCED)
    $this->extractArchaeologicalContext($rdfData, $subject, $itemData, $currentItemSetId);
    
    // 8. IMAGES/MEDIA - Extract web resources
    $this->extractMediaResources($rdfData, $subject, $itemData);
    
    error_log('Finished enhanced arrowhead processing', 3, OMEKA_PATH . '/logs/arrowhead-enhanced.log');
}




/**
 * FIXED: Property mapping issues and missing morphology/chipping data
 * Replace the extractDirectArrowheadProperties method with this corrected version
 */
private function extractDirectArrowheadProperties($rdfData, $subject, &$itemData, $currentItemSetId) {
    error_log('=== EXTRACTING DIRECT ARROWHEAD PROPERTIES ===', 3, OMEKA_PATH . '/logs/arrowhead-enhanced.log');
    
    // FIXED: Correct property variations with proper URIs
    $propertyVariations = [
        'shape' => [
            'https://purl.org/megalod/ms/ah/shape',
            'ah:shape',
            "https://purl.org/megalod/$currentItemSetId/ah/shape" // This will match your TTL
        ],
        'variant' => [
            'https://purl.org/megalod/ms/ah/variant', 
            'ah:variant',
            "https://purl.org/megalod/$currentItemSetId/ah/variant" // This will match your TTL
        ],
        'material' => [
            'http://www.cidoc-crm.org/cidoc-crm/E57_Material',
            'crm:E57_Material' // This will match your TTL
        ],
        'elongationIndex' => [
            'https://purl.org/megalod/ms/excavation/elongationIndex',
            'excav:elongationIndex' // This will match your TTL
        ],
        'thicknessIndex' => [
            'https://purl.org/megalod/ms/excavation/thicknessIndex',
            'excav:thicknessIndex'
        ]
    ];
    
    // FIXED: Correct property mappings with better labels
    $propertyMappings = [
        'shape' => ['Arrowhead Shape', 7651],
        'variant' => ['Arrowhead Variant', 7652], 
        'material' => ['Material', 4633],
        'elongationIndex' => ['Elongation Index', 7676],
        'thicknessIndex' => ['Thickness Index', 7677]
    ];
    
    // Extract each property
    foreach ($propertyVariations as $propertyName => $uriVariations) {
        $found = false;
        
        foreach ($uriVariations as $uri) {
            if (isset($rdfData[$subject][$uri])) {
                $label = $propertyMappings[$propertyName][0];
                $propertyId = $propertyMappings[$propertyName][1];
                
                if (!isset($itemData[$label])) {
                    $itemData[$label] = [];
                }
                
                foreach ($rdfData[$subject][$uri] as $valueObj) {
                    $value = $this->extractPropertyValue($valueObj);
                    if ($value) {
                        $itemData[$label][] = [
                            'type' => 'literal',
                            'property_id' => $propertyId,
                            '@value' => $value
                        ];
                        
                        error_log("Added $propertyName ($label): $value", 3, OMEKA_PATH . '/logs/arrowhead-enhanced.log');
                        $found = true;
                    }
                }
                break; // Found this property, no need to check other URI variations
            }
        }
        
        if (!$found) {
            error_log("Property $propertyName not found in any URI variation", 3, OMEKA_PATH . '/logs/arrowhead-enhanced.log');
        }
    }
}

/**
 * FIXED: Process morphology resource with correct URI patterns
 */
private function processMorphologyResource($rdfData, $morphologyUri, &$itemData) {
    error_log("Processing morphology resource: $morphologyUri", 3, OMEKA_PATH . '/logs/arrowhead-enhanced.log');
    
    // Get current item set for URI normalization
    $currentItemSetId = $this->getCurrentItemSetContext();
    
    $morphologyProperties = [
        'point' => [
            'uris' => [
                'https://purl.org/megalod/ms/ah/point', 
                'ah:point',
                "https://purl.org/megalod/$currentItemSetId/ah/point" // This matches your TTL
            ],
            'label' => 'Point Definition (Sharp/Fractured)',
            'propertyId' => 7653,
            'type' => 'boolean'
        ],
        'body' => [
            'uris' => [
                'https://purl.org/megalod/ms/ah/body', 
                'ah:body',
                "https://purl.org/megalod/$currentItemSetId/ah/body" // This matches your TTL
            ],
            'label' => 'Body Symmetry (Symmetrical/Non-symmetrical)',
            'propertyId' => 7654,
            'type' => 'boolean'
        ],
        'base' => [
            'uris' => [
                'https://purl.org/megalod/ms/ah/base', 
                'ah:base',
                "https://purl.org/megalod/$currentItemSetId/ah/base" // This matches your TTL
            ],
            'label' => 'Base Type',
            'propertyId' => 7655,
            'type' => 'uri'
        ]
    ];
    
    foreach ($morphologyProperties as $propName => $config) {
        foreach ($config['uris'] as $uri) {
            if (isset($rdfData[$morphologyUri][$uri])) {
                if (!isset($itemData[$config['label']])) {
                    $itemData[$config['label']] = [];
                }
                
                foreach ($rdfData[$morphologyUri][$uri] as $valueObj) {
                    $value = $this->extractPropertyValue($valueObj, $config['type']);
                    if ($value) {
                        $itemData[$config['label']][] = [
                            'type' => 'literal',
                            'property_id' => $config['propertyId'],
                            '@value' => $value
                        ];
                        
                        error_log("Added morphology {$config['label']}: $value", 3, OMEKA_PATH . '/logs/arrowhead-enhanced.log');
                    }
                }
                break; // Found this property, move to next
            }
        }
    }
}

/**
 * FIXED: Process chipping resource with correct URI patterns
 */
private function processChippingResource($rdfData, $chippingUri, &$itemData) {
    error_log("Processing chipping resource: $chippingUri", 3, OMEKA_PATH . '/logs/arrowhead-enhanced.log');
    
    // Get current item set for URI normalization
    $currentItemSetId = $this->getCurrentItemSetContext();
    
    $chippingProperties = [
        'chippingMode' => [
            'uris' => [
                'https://purl.org/megalod/ms/ah/chippingMode', 
                'ah:chippingMode',
                "https://purl.org/megalod/$currentItemSetId/ah/chippingMode" // This matches your TTL
            ],
            'label' => 'Chipping Mode',
            'propertyId' => 7656,
            'type' => 'uri'
        ],
        'chippingAmplitude' => [
            'uris' => [
                'https://purl.org/megalod/ms/ah/chippingAmplitude', 
                'ah:chippingAmplitude',
                "https://purl.org/megalod/$currentItemSetId/ah/chippingAmplitude" // This matches your TTL
            ],
            'label' => 'Chipping Amplitude (Marginal/Deep)',
            'propertyId' => 7657,
            'type' => 'boolean'
        ],
        'chippingDirection' => [
            'uris' => [
                'https://purl.org/megalod/ms/ah/chippingDirection', 
                'ah:chippingDirection',
                "https://purl.org/megalod/$currentItemSetId/ah/chippingDirection" // This matches your TTL
            ],
            'label' => 'Chipping Direction',
            'propertyId' => 7658,
            'type' => 'uri'
        ],
        'chippingOrientation' => [
            'uris' => [
                'https://purl.org/megalod/ms/ah/chippingOrientation', 
                'ah:chippingOrientation',
                "https://purl.org/megalod/$currentItemSetId/ah/chippingOrientation" // This matches your TTL
            ],
            'label' => 'Chipping Orientation (Lateral/Transverse)',
            'propertyId' => 7659,
            'type' => 'boolean'
        ],
        'chippingDelineation' => [
            'uris' => [
                'https://purl.org/megalod/ms/ah/chippingDelineation', 
                'ah:chippingDelineation',
                "https://purl.org/megalod/$currentItemSetId/ah/chippingDelineation" // This matches your TTL
            ],
            'label' => 'Chipping Delineation',
            'propertyId' => 7660,
            'type' => 'uri'
        ],
        'chippingLocationSide' => [
            'uris' => [
                'https://purl.org/megalod/ms/ah/chippingLocationSide', 
                'ah:chippingLocationSide',
                "https://purl.org/megalod/$currentItemSetId/ah/chippingLocationSide" // This matches your TTL
            ],
            'label' => 'Chipping Location Side',
            'propertyId' => 7662,
            'type' => 'uri_multiple'
        ],
        'chippingLocationTransversal' => [
            'uris' => [
                'https://purl.org/megalod/ms/ah/chippingLocationTransversal', 
                'ah:chippingLocationTransversal',
                "https://purl.org/megalod/$currentItemSetId/ah/chippingLocationTransversal" // This matches your TTL
            ],
            'label' => 'Chipping Location Transversal',
            'propertyId' => 7663,
            'type' => 'uri_multiple'
        ],
        'chippingShape' => [
            'uris' => [
                'https://purl.org/megalod/ms/ah/chippingShape', 
                'ah:chippingShape',
                "https://purl.org/megalod/$currentItemSetId/ah/chippingShape" // This matches your TTL
            ],
            'label' => 'Chipping Shape',
            'propertyId' => 7661,
            'type' => 'uri'
        ]
    ];
    
    foreach ($chippingProperties as $propName => $config) {
        foreach ($config['uris'] as $uri) {
            if (isset($rdfData[$chippingUri][$uri])) {
                if (!isset($itemData[$config['label']])) {
                    $itemData[$config['label']] = [];
                }
                
                // Handle multiple values for location properties
                foreach ($rdfData[$chippingUri][$uri] as $valueObj) {
                    $value = $this->extractPropertyValue($valueObj, $config['type']);
                    if ($value) {
                        $itemData[$config['label']][] = [
                            'type' => 'literal',
                            'property_id' => $config['propertyId'],
                            '@value' => $value
                        ];
                        
                        error_log("Added chipping {$config['label']}: $value", 3, OMEKA_PATH . '/logs/arrowhead-enhanced.log');
                    }
                }
                break; // Found this property, move to next
            }
        }
    }
}

/**
 * FIXED: Enhanced property value extraction with better boolean handling
 */
private function extractPropertyValue($valueObj, $type = 'auto') {
    if ($valueObj['type'] === 'literal') {
        $value = $valueObj['value'];
        
        // Handle boolean values based on context
        if ($type === 'boolean' || $value === 'true' || $value === 'false') {
            if ($value === 'true') {
                // Context-specific boolean interpretation
                switch ($type) {
                    case 'morphology_point':
                        return 'Sharp';
                    case 'morphology_body':
                        return 'Symmetrical';
                    case 'chipping_amplitude':
                        return 'Marginal';
                    case 'chipping_orientation':
                        return 'Lateral';
                    default:
                        return 'True';
                }
            } elseif ($value === 'false') {
                switch ($type) {
                    case 'morphology_point':
                        return 'Fractured';
                    case 'morphology_body':
                        return 'Non-symmetrical';
                    case 'chipping_amplitude':
                        return 'Deep';
                    case 'chipping_orientation':
                        return 'Transverse';
                    default:
                        return 'False';
                }
            }
        }
        
        return $value;
    } elseif ($valueObj['type'] === 'uri') {
        // Extract meaningful part from URI
        if (strpos($valueObj['value'], '/kos/') !== false || 
            strpos($valueObj['value'], '/ah-') !== false ||
            strpos($valueObj['value'], '/MegaLOD-') !== false) {
            $parts = explode('/', $valueObj['value']);
            $lastPart = end($parts);
            
            // Handle different URI patterns
            if (strpos($lastPart, 'ah-') === 0) {
                $clean = substr($lastPart, 3); // Remove 'ah-' prefix
            } elseif (strpos($lastPart, 'MegaLOD-Index') === 0) {
                $clean = str_replace('MegaLOD-Index', '', $lastPart);
            } else {
                $clean = $lastPart;
            }
            
            return ucfirst(str_replace('-', ' ', $clean));
        } else {
            $parts = explode('/', $valueObj['value']);
            return ucfirst(end($parts));
        }
    }
    
    return null;
}

/**
 * FIXED: Extract ALL measurements with proper unit handling
 */
private function extractAllMeasurements($rdfData, $subject, &$itemData, $currentItemSetId) {
    error_log('=== EXTRACTING ALL MEASUREMENTS ===', 3, OMEKA_PATH . '/logs/arrowhead-enhanced.log');
    
    // Define all measurement properties with correct URIs
    $measurementProperties = [
        'height' => [
            'uris' => [
                'http://schema.org/height', 
                'schema:height'
            ],
            'label' => 'Height',
            'propertyId' => 5616
        ],
        'width' => [
            'uris' => [
                'http://schema.org/width', 
                'schema:width'
            ],
            'label' => 'Width', 
            'propertyId' => 5688
        ],
        'depth' => [
            'uris' => [
                'http://schema.org/depth', 
                'schema:depth'
            ],
            'label' => 'Thickness',
            'propertyId' => 7244
        ],
        'weight' => [
            'uris' => [
                'http://schema.org/weight', 
                'schema:weight'
            ],
            'label' => 'Weight',
            'propertyId' => 5779
        ],
        'bodyLength' => [
            'uris' => [
                'https://purl.org/megalod/ms/ah/hasBodyLength', 
                'ah:hasBodyLength',
                "https://purl.org/megalod/$currentItemSetId/ah/hasBodyLength" // This matches your TTL
            ],
            'label' => 'Body Length',
            'propertyId' => 7678
        ],
        'baseLength' => [
            'uris' => [
                'https://purl.org/megalod/ms/ah/hasBaseLength', 
                'ah:hasBaseLength',
                "https://purl.org/megalod/$currentItemSetId/ah/hasBaseLength" // This matches your TTL
            ],
            'label' => 'Base Length', 
            'propertyId' => 7679
        ]
    ];
    
    foreach ($measurementProperties as $measurementName => $config) {
        $found = false;
        
        foreach ($config['uris'] as $uri) {
            if (isset($rdfData[$subject][$uri])) {
                error_log("Found measurement property: $uri", 3, OMEKA_PATH . '/logs/arrowhead-enhanced.log');
                
                foreach ($rdfData[$subject][$uri] as $measObj) {
                    if ($measObj['type'] === 'uri' && isset($rdfData[$measObj['value']])) {
                        $measurementUri = $measObj['value'];
                        error_log("Processing measurement URI: $measurementUri", 3, OMEKA_PATH . '/logs/arrowhead-enhanced.log');
                        
                        // Extract value and unit
                        $value = $this->extractMeasurementValue($rdfData, $measurementUri);
                        $unit = $this->extractMeasurementUnit($rdfData, $measurementUri);
                        
                        if ($value !== null) {
                            $displayValue = $value;
                            if ($unit) {
                                // Clean up unit - handle <MMT> format
                                $cleanUnit = str_replace(['<', '>'], '', $unit);
                                $displayValue .= " " . $cleanUnit;
                            }
                            
                            if (!isset($itemData[$config['label']])) {
                                $itemData[$config['label']] = [];
                            }
                            
                            $itemData[$config['label']][] = [
                                'type' => 'literal',
                                'property_id' => $config['propertyId'],
                                '@value' => $displayValue
                            ];
                            
                            error_log("Added measurement {$config['label']}: $displayValue", 3, OMEKA_PATH . '/logs/arrowhead-enhanced.log');
                            $found = true;
                        }
                    }
                }
                break; // Found this measurement, no need to check other URIs
            }
        }
        
        if (!$found) {
            error_log("Measurement $measurementName not found", 3, OMEKA_PATH . '/logs/arrowhead-enhanced.log');
        }
    }
}


/**
 * Extract complete morphology data - scan ALL resources for morphology objects
 */
private function extractCompleteMorphologyData($rdfData, $subject, &$itemData, $currentItemSetId) {
    error_log('=== EXTRACTING COMPLETE MORPHOLOGY DATA ===', 3, OMEKA_PATH . '/logs/arrowhead-enhanced.log');
    
    // First try to find morphology via hasMorphology property
    $morphologyUris = [
        'https://purl.org/megalod/ms/ah/hasMorphology',
        'ah:hasMorphology'
    ];
    
    if ($currentItemSetId) {
        $morphologyUris[] = "https://purl.org/megalod/$currentItemSetId/ah/hasMorphology";
    }
    
    $morphologyFound = false;
    
    // Strategy 1: Find via hasMorphology property
    foreach ($morphologyUris as $morphologyUri) {
        if (isset($rdfData[$subject][$morphologyUri])) {
            foreach ($rdfData[$subject][$morphologyUri] as $morphObj) {
                if ($morphObj['type'] === 'uri' && isset($rdfData[$morphObj['value']])) {
                    $this->processMorphologyResource($rdfData, $morphObj['value'], $itemData);
                    $morphologyFound = true;
                }
            }
        }
    }
    
    // Strategy 2: Scan ALL resources for morphology types
    if (!$morphologyFound) {
        error_log('No morphology found via property reference, scanning all resources...', 3, OMEKA_PATH . '/logs/arrowhead-enhanced.log');
        
        foreach ($rdfData as $resourceUri => $properties) {
            if (isset($properties['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'])) {
                foreach ($properties['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'] as $typeObj) {
                    if ($typeObj['type'] === 'uri' && 
                        (strpos($typeObj['value'], 'Morphology') !== false ||
                         $typeObj['value'] === 'https://purl.org/megalod/ms/ah/Morphology' ||
                         $typeObj['value'] === 'ah:Morphology')) {
                        
                        error_log("Found morphology resource by type scan: $resourceUri", 3, OMEKA_PATH . '/logs/arrowhead-enhanced.log');
                        $this->processMorphologyResource($rdfData, $resourceUri, $itemData);
                        $morphologyFound = true;
                    }
                }
            }
        }
    }
    
    if (!$morphologyFound) {
        error_log('No morphology data found for arrowhead', 3, OMEKA_PATH . '/logs/arrowhead-enhanced.log');
    }
}




/**
 * Extract complete chipping data - scan ALL resources for chipping objects
 */
private function extractCompleteChippingData($rdfData, $subject, &$itemData, $currentItemSetId) {
    error_log('=== EXTRACTING COMPLETE CHIPPING DATA ===', 3, OMEKA_PATH . '/logs/arrowhead-enhanced.log');
    
    // First try to find chipping via hasChipping property
    $chippingUris = [
        'https://purl.org/megalod/ms/ah/hasChipping',
        'ah:hasChipping'
    ];
    
    if ($currentItemSetId) {
        $chippingUris[] = "https://purl.org/megalod/$currentItemSetId/ah/hasChipping";
    }
    
    $chippingFound = false;
    
    // Strategy 1: Find via hasChipping property
    foreach ($chippingUris as $chippingUri) {
        if (isset($rdfData[$subject][$chippingUri])) {
            foreach ($rdfData[$subject][$chippingUri] as $chipObj) {
                if ($chipObj['type'] === 'uri' && isset($rdfData[$chipObj['value']])) {
                    $this->processChippingResource($rdfData, $chipObj['value'], $itemData);
                    $chippingFound = true;
                }
            }
        }
    }
    
    // Strategy 2: Scan ALL resources for chipping types
    if (!$chippingFound) {
        error_log('No chipping found via property reference, scanning all resources...', 3, OMEKA_PATH . '/logs/arrowhead-enhanced.log');
        
        foreach ($rdfData as $resourceUri => $properties) {
            if (isset($properties['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'])) {
                foreach ($properties['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'] as $typeObj) {
                    if ($typeObj['type'] === 'uri' && 
                        (strpos($typeObj['value'], 'Chipping') !== false ||
                         $typeObj['value'] === 'https://purl.org/megalod/ms/ah/Chipping' ||
                         $typeObj['value'] === 'ah:Chipping')) {
                        
                        error_log("Found chipping resource by type scan: $resourceUri", 3, OMEKA_PATH . '/logs/arrowhead-enhanced.log');
                        $this->processChippingResource($rdfData, $resourceUri, $itemData);
                        $chippingFound = true;
                    }
                }
            }
        }
    }
    
    if (!$chippingFound) {
        error_log('No chipping data found for arrowhead', 3, OMEKA_PATH . '/logs/arrowhead-enhanced.log');
    }
}



/**
 * Extract coordinate data - scan ALL resources for coordinate objects
 */
private function extractCoordinateData($rdfData, $subject, &$itemData, $currentItemSetId) {
    error_log('=== EXTRACTING COORDINATE DATA ===', 3, OMEKA_PATH . '/logs/arrowhead-enhanced.log');
    
    // Try to find coordinates via hasCoordinatesInSquare property
    $coordinateUris = [
        'https://purl.org/megalod/ms/excavation/hasCoordinatesInSquare',
        'excav:hasCoordinatesInSquare'
    ];
    
    if ($currentItemSetId) {
        $coordinateUris[] = "https://purl.org/megalod/$currentItemSetId/excavation/hasCoordinatesInSquare";
    }
    
    $coordinatesFound = false;
    
    // Strategy 1: Find via hasCoordinatesInSquare property
    foreach ($coordinateUris as $coordinateUri) {
        if (isset($rdfData[$subject][$coordinateUri])) {
            foreach ($rdfData[$subject][$coordinateUri] as $coordObj) {
                if ($coordObj['type'] === 'uri' && isset($rdfData[$coordObj['value']])) {
                    $this->processCoordinateResource($rdfData, $coordObj['value'], $itemData);
                    $coordinatesFound = true;
                }
            }
        }
    }
    
    // Strategy 2: Scan ALL resources for coordinate types
    if (!$coordinatesFound) {
        error_log('No coordinates found via property reference, scanning all resources...', 3, OMEKA_PATH . '/logs/arrowhead-enhanced.log');
        
        foreach ($rdfData as $resourceUri => $properties) {
            if (isset($properties['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'])) {
                foreach ($properties['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'] as $typeObj) {
                    if ($typeObj['type'] === 'uri' && 
                        (strpos($typeObj['value'], 'Coordinates') !== false ||
                         $typeObj['value'] === 'https://purl.org/megalod/ms/excavation/Coordinates' ||
                         $typeObj['value'] === 'excav:Coordinates')) {
                        
                        error_log("Found coordinate resource by type scan: $resourceUri", 3, OMEKA_PATH . '/logs/arrowhead-enhanced.log');
                        $this->processCoordinateResource($rdfData, $resourceUri, $itemData);
                        $coordinatesFound = true;
                    }
                }
            }
        }
    }
    
    if (!$coordinatesFound) {
        error_log('No coordinate data found for arrowhead', 3, OMEKA_PATH . '/logs/arrowhead-enhanced.log');
    }
}



/**
 * Process a coordinate resource and extract all values
 */
private function processCoordinateResource($rdfData, $coordinateUri, &$itemData) {
    error_log("Processing coordinate resource: $coordinateUri", 3, OMEKA_PATH . '/logs/coordinates-debug.log');
    
    // Initialize individual coordinate components
    $coordinates = [
        'X' => null,
        'Y' => null,
        'Z' => null
    ];
    
    // Check for standard schema:value pattern (multiple values)
    if (isset($rdfData[$coordinateUri]['http://schema.org/value'])) {
        $values = $rdfData[$coordinateUri]['http://schema.org/value'];
        error_log('Found ' . count($values) . ' schema:value coordinates', 3, OMEKA_PATH . '/logs/coordinates-debug.log');
        
        // Process multiple schema:value entries in order
        foreach ($values as $index => $valueObj) {
            if ($valueObj['type'] === 'literal') {
                $key = isset(['X', 'Y', 'Z'][$index]) ? ['X', 'Y', 'Z'][$index] : "Value$index";
                $coordinates[$key] = $valueObj['value'];
                error_log("Added coordinate $key: {$valueObj['value']}", 3, OMEKA_PATH . '/logs/coordinates-debug.log');
            }
        }
    }
    
    // Check for geo:longitude (X coordinate)
    if (isset($rdfData[$coordinateUri]['http://www.w3.org/2003/01/geo/wgs84_pos#longitude'])) {
        foreach ($rdfData[$coordinateUri]['http://www.w3.org/2003/01/geo/wgs84_pos#longitude'] as $xObj) {
            if ($xObj['type'] === 'uri' && isset($rdfData[$xObj['value']])) {
                $xValue = $this->extractMeasurementValue($rdfData, $xObj['value']);
                $xUnit = $this->extractMeasurementUnit($rdfData, $xObj['value']);
                $coordinates['X'] = $xValue . ($xUnit ? " $xUnit" : "");
                error_log("Added X coordinate from geo:longitude: {$coordinates['X']}", 3, OMEKA_PATH . '/logs/coordinates-debug.log');
            } else if ($xObj['type'] === 'literal') {
                $coordinates['X'] = $xObj['value'];
                error_log("Added X coordinate from geo:longitude literal: {$coordinates['X']}", 3, OMEKA_PATH . '/logs/coordinates-debug.log');
            }
        }
    }
    
    // Check for geo:latitude (Y coordinate)
    if (isset($rdfData[$coordinateUri]['http://www.w3.org/2003/01/geo/wgs84_pos#latitude'])) {
        foreach ($rdfData[$coordinateUri]['http://www.w3.org/2003/01/geo/wgs84_pos#latitude'] as $yObj) {
            if ($yObj['type'] === 'uri' && isset($rdfData[$yObj['value']])) {
                $yValue = $this->extractMeasurementValue($rdfData, $yObj['value']);
                $yUnit = $this->extractMeasurementUnit($rdfData, $yObj['value']);
                $coordinates['Y'] = $yValue . ($yUnit ? " $yUnit" : "");
                error_log("Added Y coordinate from geo:latitude: {$coordinates['Y']}", 3, OMEKA_PATH . '/logs/coordinates-debug.log');
            } else if ($yObj['type'] === 'literal') {
                $coordinates['Y'] = $yObj['value'];
                error_log("Added Y coordinate from geo:latitude literal: {$coordinates['Y']}", 3, OMEKA_PATH . '/logs/coordinates-debug.log');
            }
        }
    }
    
    // Check for schema:depth (Z coordinate)
    if (isset($rdfData[$coordinateUri]['http://schema.org/depth'])) {
        foreach ($rdfData[$coordinateUri]['http://schema.org/depth'] as $zObj) {
            if ($zObj['type'] === 'uri' && isset($rdfData[$zObj['value']])) {
                $zValue = $this->extractMeasurementValue($rdfData, $zObj['value']);
                $zUnit = $this->extractMeasurementUnit($rdfData, $zObj['value']);
                $coordinates['Z'] = $zValue . ($zUnit ? " $zUnit" : "");
                error_log("Added Z coordinate from schema:depth: {$coordinates['Z']}", 3, OMEKA_PATH . '/logs/coordinates-debug.log');
            } else if ($zObj['type'] === 'literal') {
                $coordinates['Z'] = $zObj['value'];
                error_log("Added Z coordinate from schema:depth literal: {$coordinates['Z']}", 3, OMEKA_PATH . '/logs/coordinates-debug.log');
            }
        }
    }
    
    // Create a formatted coordinate string with all available values
    $coordStrings = [];
    foreach ($coordinates as $axis => $value) {
        if ($value !== null) {
            $coordStrings[] = "$axis: $value";
        }
    }
    
    if (!empty($coordStrings)) {
        if (!isset($itemData['Coordinates'])) {
            $itemData['Coordinates'] = [];
        }
        
        $coordDisplay = implode(', ', $coordStrings);
        $itemData['Coordinates'][] = [
            'type' => 'literal',
            'property_id' => 7674,
            '@value' => $coordDisplay
        ];
        
        error_log("Added coordinates: $coordDisplay", 3, OMEKA_PATH . '/logs/coordinates-debug.log');
    } else {
        error_log("No coordinate values found for URI: $coordinateUri", 3, OMEKA_PATH . '/logs/coordinates-debug.log');
    }
}



/**
 * ENHANCED: Process encounter event and update the list of encountered objects
 */
private function processEncounterEvent($rdfData, $encounterUri, &$itemData, $currentItemSetId) {
    error_log("Processing complete encounter event: $encounterUri", 3, OMEKA_PATH . '/logs/encounter-debug.log');
    
    // Extract encounter date
    if (isset($rdfData[$encounterUri]['http://purl.org/dc/terms/date'])) {
        foreach ($rdfData[$encounterUri]['http://purl.org/dc/terms/date'] as $dateObj) {
            if ($dateObj['type'] === 'literal') {
                if (!isset($itemData['Encounter Date'])) {
                    $itemData['Encounter Date'] = [];
                }
                
                $itemData['Encounter Date'][] = [
                    'type' => 'literal',
                    'property_id' => 7675,
                    '@value' => $dateObj['value']
                ];
                
                error_log("Added encounter date: {$dateObj['value']}", 3, OMEKA_PATH . '/logs/encounter-debug.log');
            }
        }
    }
    
// Extract all encountered objects - FIXED: Create proper resource links
    $encounteredObjects = [];
    $encounteredItemUris = [];
    
    if (isset($rdfData[$encounterUri]['https://cidoc-crm.org/extensions/crmsci/O19_encountered_object']) || 
        isset($rdfData[$encounterUri]['crmsci:O19_encountered_object'])) {
        
        // Try different property variations
        $propertyVariations = [
            'https://cidoc-crm.org/extensions/crmsci/O19_encountered_object',
            'crmsci:O19_encountered_object'
        ];
        
        // Add item set-specific variant if available
        if ($currentItemSetId) {
            $propertyVariations[] = "https://purl.org/megalod/$currentItemSetId/crmsci/O19_encountered_object";
        }
        
        // Process each property variant
        foreach ($propertyVariations as $propertyUri) {
            if (isset($rdfData[$encounterUri][$propertyUri])) {
                foreach ($rdfData[$encounterUri][$propertyUri] as $itemObj) {
                    if ($itemObj['type'] === 'uri') {
                        $itemUri = $itemObj['value'];
                        $encounteredItemUris[] = $itemUri;
                        
                        // Get more info about the item
                        $itemId = null;
                        $identifier = $this->extractIdentifierFromUri($itemUri);
                        
                        if (isset($rdfData[$itemUri]) && 
                            isset($rdfData[$itemUri]['http://purl.org/dc/terms/identifier'])) {
                            foreach ($rdfData[$itemUri]['http://purl.org/dc/terms/identifier'] as $idObj) {
                                if ($idObj['type'] === 'literal') {
                                    $identifier = $idObj['value'];
                                }
                            }
                        }
                        
                        // Store both the identifier and URI for later processing
                        $encounteredObjects[] = [
                            'identifier' => $identifier ?: basename($itemUri),
                            'uri' => $itemUri
                        ];
                    }
                }
            }
        }
    }
    
    // ENHANCED: Add encountered objects as both literal list and item references
    if (!empty($encounteredObjects)) {
        // 1. Add text list of identifiers for backward compatibility
        if (!isset($itemData['Encountered Objects'])) {
            $itemData['Encountered Objects'] = [];
        }
        
        $identifierList = array_map(function($obj) { 
            return $obj['identifier']; 
        }, $encounteredObjects);
        
        $itemData['Encountered Objects'][] = [
            'type' => 'literal',
            'property_id' => 7685,
            '@value' => implode(', ', $identifierList)
        ];
        
        error_log("Added encountered objects text list: " . implode(', ', $identifierList), 3, OMEKA_PATH . '/logs/encounter-debug.log');
        
        // 2. NEW: Add each encountered object as an item reference
        if (!isset($itemData['Encountered Item'])) {
            $itemData['Encountered Item'] = [];
        }
        
        // For each object URI, try to find the corresponding Omeka item
        foreach ($encounteredObjects as $encObj) {
            $identifier = $encObj['identifier'];
            $uri = $encObj['uri'];
            
            // Try to find the item by identifier in the current item set
            $item = $this->findItemByIdentifier($identifier, $currentItemSetId);
            
            if ($item) {
                // Found the item, add as a resource reference
                $itemData['Encountered Item'][] = [
                    'type' => 'resource',
                    'property_id' => 7686, // Use an appropriate property ID for item links
                    'value_resource_id' => $item->id()
                ];
                
                error_log("Added encountered item reference: Item ID " . $item->id() . " ($identifier)", 3, OMEKA_PATH . '/logs/encounter-debug.log');
            } else {
                // Item not found - add as URI reference for now
                $itemData['Encountered Item'][] = [
                    'type' => 'uri',
                    'property_id' => 7686,
                    '@id' => $uri,
                    'o:label' => $identifier
                ];
                
                error_log("Added encountered item as URI reference: $uri ($identifier) - item not found in Omeka", 3, OMEKA_PATH . '/logs/encounter-debug.log');
            }
        }
    } else {
        error_log("No encountered objects found for encounter event: $encounterUri", 3, OMEKA_PATH . '/logs/encounter-debug.log');
    }
    
    // Extract depth information
    if (isset($rdfData[$encounterUri]['http://dbpedia.org/ontology/depth'])) {
        foreach ($rdfData[$encounterUri]['http://dbpedia.org/ontology/depth'] as $depthObj) {
            if ($depthObj['type'] === 'literal') {
                if (!isset($itemData['Discovery Depth'])) {
                    $itemData['Discovery Depth'] = [];
                }
                
                $itemData['Discovery Depth'][] = [
                    'type' => 'literal',
                    'property_id' => 7676,
                    '@value' => $depthObj['value']
                ];
                
                error_log("Added discovery depth: {$depthObj['value']}", 3, OMEKA_PATH . '/logs/encounter-debug.log');
            }
        }
    }
    
    // Create URI patterns for all context references
    $contextPatterns = [
        'excav:foundInExcavation' => [
            'label' => 'The Encounter Event - an item found in an Excavation',
            'propertyId' => 7673,
            'variantUris' => [
                'https://purl.org/megalod/ms/excavation/foundInExcavation',
                'excav:foundInExcavation'
            ]
        ],
        'excav:foundInLocation' => [
            'label' => 'Item found in a Location',
            'propertyId' => 7680,
            'variantUris' => [
                'https://purl.org/megalod/ms/excavation/foundInLocation',
                'excav:foundInLocation'
            ]
        ],
        'excav:foundInSquare' => [
            'label' => 'The Item found in a Square',
            'propertyId' => 7683,
            'variantUris' => [
                'https://purl.org/megalod/ms/excavation/foundInSquare',
                'excav:foundInSquare'
            ]
        ],
        'excav:foundInContext' => [
            'label' => 'The Encounter Event - an item found in a specific Context',
            'propertyId' => 7672,
            'variantUris' => [
                'https://purl.org/megalod/ms/excavation/foundInContext',
                'excav:foundInContext'
            ]
        ],
        'excav:foundInSVU' => [
            'label' => 'The Item found in a SVU',
            'propertyId' => 7671,
            'variantUris' => [
                'https://purl.org/megalod/ms/excavation/foundInSVU',
                'excav:foundInSVU'
            ]
        ]
    ];
    
    // Add itemset-specific variants for each property
    if ($currentItemSetId) {
        foreach ($contextPatterns as $key => $config) {
            $baseProperty = str_replace('excav:', '', $key);
            $contextPatterns[$key]['variantUris'][] = "https://purl.org/megalod/$currentItemSetId/excavation/$baseProperty";
        }
    }
    
    // Process all context references
    foreach ($contextPatterns as $key => $config) {
        $found = false;
        
        foreach ($config['variantUris'] as $predicateUri) {
            if (isset($rdfData[$encounterUri][$predicateUri])) {
                error_log("Found $key reference with predicate: $predicateUri", 3, OMEKA_PATH . '/logs/encounter-debug.log');
                
                foreach ($rdfData[$encounterUri][$predicateUri] as $contextObj) {
                    if ($contextObj['type'] === 'uri') {
                        $contextUri = $contextObj['value'];
                        $displayValue = $this->extractContextDisplayValue($rdfData, $contextUri) ?: $contextUri;
                        
                        if (!isset($itemData[$config['label']])) {
                            $itemData[$config['label']] = [];
                        }
                        
                        // Store as URI link, not just plain text
                        $itemData[$config['label']][] = [
                            'type' => 'uri',
                            'property_id' => $config['propertyId'],
                            '@id' => $contextUri,
                            'o:label' => $displayValue
                        ];
                        
                        error_log("Added {$config['label']} URI reference: $contextUri (label: $displayValue)", 3, OMEKA_PATH . '/logs/encounter-debug.log');
                        $found = true;
                    }
                }
                
                if ($found) break; // Found references with this predicate, no need to check others
            }
        }
    }
    
    error_log("Completed encounter event processing", 3, OMEKA_PATH . '/logs/encounter-debug.log');
}


/**
 * Find an item by identifier with better logging and error handling
 */
private function findItemByIdentifierWithLogging($identifier, $itemSetId = null) {
    error_log("Finding item with identifier: $identifier in item set: " . ($itemSetId ?: 'any'), 3, OMEKA_PATH . '/logs/encounter-item-links.log');
    
    try {
        // Search by exact identifier match
        $searchParams = [
            'property' => [
                [
                    'property' => 10, // dcterms:identifier property ID
                    'type' => 'eq',
                    'text' => $identifier
                ]
            ]
        ];
        
        if ($itemSetId) {
            $searchParams['item_set_id'] = $itemSetId;
        }
        
        $response = $this->api()->search('items', $searchParams);
        $items = $response->getContent();
        
        if (!empty($items)) {
            $item = $items[0];
            error_log("✓ Found item by identifier $identifier: Item ID " . $item->id(), 3, OMEKA_PATH . '/logs/encounter-item-links.log');
            return $item;
        }
        
        // If not found and we have an item set constraint, try without it
        if ($itemSetId) {
            error_log("Item not found in specified item set, trying global search", 3, OMEKA_PATH . '/logs/encounter-item-links.log');
            return $this->findItemByIdentifierWithLogging($identifier);
        }
        
        error_log("✗ No item found with identifier: $identifier", 3, OMEKA_PATH . '/logs/encounter-item-links.log');
        return null;
    } catch (\Exception $e) {
        error_log("Error searching for item: " . $e->getMessage(), 3, OMEKA_PATH . '/logs/encounter-item-links.log');
        return null;
    }
}


/**
 * COMPLETELY REWRITTEN: Extract archaeological context with proper URI handling
 */
private function extractArchaeologicalContext($rdfData, $subject, &$itemData, $currentItemSetId) {
    error_log('=== EXTRACTING ARCHAEOLOGICAL CONTEXT ===', 3, OMEKA_PATH . '/logs/arrowhead-enhanced.log');
    
    // Context properties to extract with correct URI patterns
    $contextProperties = [
        'foundInExcavation' => [
            'uris' => [
                'https://purl.org/megalod/ms/excavation/foundInExcavation', 
                'excav:foundInExcavation',
                "https://purl.org/megalod/$currentItemSetId/excavation/foundInExcavation"
            ],
            'label' => 'Found in Excavation',
            'propertyId' => 7673
        ],
        'foundInLocation' => [
            'uris' => [
                'https://purl.org/megalod/ms/excavation/foundInLocation', 
                'excav:foundInLocation',
                "https://purl.org/megalod/$currentItemSetId/excavation/foundInLocation"
            ],
            'label' => 'Found in Location',
            'propertyId' => 7680
        ],
        'foundInSquare' => [
            'uris' => [
                'https://purl.org/megalod/ms/excavation/foundInSquare', 
                'excav:foundInSquare',
                "https://purl.org/megalod/$currentItemSetId/excavation/foundInSquare"
            ],
            'label' => 'Found in Square',
            'propertyId' => 7683
        ],
        'foundInContext' => [
            'uris' => [
                'https://purl.org/megalod/ms/excavation/foundInContext', 
                'excav:foundInContext',
                "https://purl.org/megalod/$currentItemSetId/excavation/foundInContext",
                // NEW: Add these literal URI strings that appear in valid-arrow.ttl
                'http://purl.org/megalod/ms/excavation/foundInContext',
                'https://purl.org/megalod/ms/excavation/foundInContext',
                // IMPORTANT: This is exactly how it appears in your TTL
                'excav:foundInContext'
            ],
            'label' => 'Found in Context',
            'propertyId' => 7672
        ],
        'foundInSVU' => [
            'uris' => [
                'https://purl.org/megalod/ms/excavation/foundInSVU', 
                'excav:foundInSVU',
                "https://purl.org/megalod/$currentItemSetId/excavation/foundInSVU"
            ],
            'label' => 'Found in SVU',
            'propertyId' => 7671
        ]
    ];
    
    foreach ($contextProperties as $propName => $config) {
        foreach ($config['uris'] as $uri) {
            // Log to see if this URI is found
            if (isset($rdfData[$subject][$uri])) {
                error_log("✓ FOUND context property: $uri", 3, OMEKA_PATH . '/logs/context-debug.log');
                
                if (!isset($itemData[$config['label']])) {
                    $itemData[$config['label']] = [];
                }
                
                foreach ($rdfData[$subject][$uri] as $contextObj) {
                    if ($contextObj['type'] === 'uri') {
                        // Extract meaningful identifier or use URI
                        $contextValue = $this->extractContextDisplayValue($rdfData, $contextObj['value']);
                        $displayValue = $contextValue ?: $contextObj['value'];
                        
                        // Log the actual value being added
                        error_log("Adding context value: $displayValue", 3, OMEKA_PATH . '/logs/context-debug.log');
                        
                        $itemData[$config['label']][] = [
                            'type' => 'uri',  // Use URI type to create proper links
                            'property_id' => $config['propertyId'],
                            '@id' => $contextObj['value'],
                            'o:label' => $displayValue
                        ];
                        
                        error_log("Added {$config['label']}: $displayValue", 3, OMEKA_PATH . '/logs/arrowhead-enhanced.log');
                    }
                }
                break; // Found this property, move to next
            } else {
                error_log("✗ NOT FOUND: $uri", 3, OMEKA_PATH . '/logs/context-debug.log');
            }
        }
    }
}


/**
 * ENHANCED: Extract encounter event data with proper URI references
 */
private function extractEncounterEventData($rdfData, $subject, &$itemData, $currentItemSetId) {
    error_log('=== EXTRACTING ENCOUNTER EVENT DATA ===', 3, OMEKA_PATH . '/logs/encounter-debug.log');
    
    // First, find encounter event via direct property
    $encounterUris = [
        'https://cidoc-crm.org/extensions/crmsci/O19i_was_object_encountered_through',
        'crmsci:O19i_was_object_encountered_through'
    ];
    
    // Add item set specific variant
    if ($currentItemSetId) {
        $encounterUris[] = "https://purl.org/megalod/$currentItemSetId/crmsci/O19i_was_object_encountered_through";
    }
    
    $encounterEventUri = null;
    
    // Strategy 1: Find via direct property reference
    foreach ($encounterUris as $uri) {
        if (isset($rdfData[$subject][$uri])) {
            foreach ($rdfData[$subject][$uri] as $encounterObj) {
                if ($encounterObj['type'] === 'uri') {
                    $encounterEventUri = $encounterObj['value'];
                    error_log("Found encounter event via property: $encounterEventUri", 3, OMEKA_PATH . '/logs/encounter-debug.log');
                    break 2;
                }
            }
        }
    }
    
    // Strategy 2: Scan for encounter events that reference this arrowhead
    if (!$encounterEventUri) {
        error_log('No encounter event found via property, scanning all resources...', 3, OMEKA_PATH . '/logs/encounter-debug.log');
        
        foreach ($rdfData as $resourceUri => $properties) {
            if (isset($properties['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'])) {
                foreach ($properties['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'] as $typeObj) {
                    if ($typeObj['type'] === 'uri' && 
                        (strpos($typeObj['value'], 'EncounterEvent') !== false ||
                         $typeObj['value'] === 'https://purl.org/megalod/ms/excavation/EncounterEvent' ||
                         $typeObj['value'] === 'excav:EncounterEvent')) {
                        
                        // Check if this encounter event references our arrowhead
                        $encounterObjectUris = [
                            'https://cidoc-crm.org/extensions/crmsci/O19_encountered_object',
                            'crmsci:O19_encountered_object'
                        ];
                        
                        if ($currentItemSetId) {
                            $encounterObjectUris[] = "https://purl.org/megalod/$currentItemSetId/crmsci/O19_encountered_object";
                        }
                        
                        foreach ($encounterObjectUris as $objUri) {
                            if (isset($properties[$objUri])) {
                                foreach ($properties[$objUri] as $objRef) {
                                    if ($objRef['type'] === 'uri' && $objRef['value'] === $subject) {
                                        $encounterEventUri = $resourceUri;
                                        error_log("Found encounter event by scanning: $encounterEventUri", 3, OMEKA_PATH . '/logs/encounter-debug.log');
                                        break 3;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
    
    // Process the encounter event if found
    if ($encounterEventUri && isset($rdfData[$encounterEventUri])) {
        $this->processEncounterEvent($rdfData, $encounterEventUri, $itemData, $this->getCurrentItemSetContext());
    } else {
        error_log('No encounter event found for this arrowhead', 3, OMEKA_PATH . '/logs/encounter-debug.log');
    }
}

/**
 * NEW: Process complete encounter event with all context information
 */
private function processEncounterEventComplete($rdfData, $encounterUri, &$itemData, $currentItemSetId) {
    error_log("Processing complete encounter event: $encounterUri", 3, OMEKA_PATH . '/logs/arrowhead-enhanced.log');
    
    // Extract basic encounter information
    $encounterInfo = [];
    
    // Extract date
    if (isset($rdfData[$encounterUri]['http://purl.org/dc/terms/date'])) {
        foreach ($rdfData[$encounterUri]['http://purl.org/dc/terms/date'] as $dateObj) {
            if ($dateObj['type'] === 'literal') {
                $encounterInfo[] = 'Date: ' . $dateObj['value'];
                error_log("Added encounter date: {$dateObj['value']}", 3, OMEKA_PATH . '/logs/arrowhead-enhanced.log');
            }
        }
    }
    
    // Extract depth if available
    if (isset($rdfData[$encounterUri]['http://dbpedia.org/ontology/depth'])) {
        foreach ($rdfData[$encounterUri]['http://dbpedia.org/ontology/depth'] as $depthObj) {
            if ($depthObj['type'] === 'literal') {
                $encounterInfo[] = 'Depth: ' . $depthObj['value'];
                error_log("Added encounter depth: {$depthObj['value']}", 3, OMEKA_PATH . '/logs/arrowhead-enhanced.log');
            }
        }
    }
    
    // Extract excavation reference
    $excavationUris = [
        'https://purl.org/megalod/ms/excavation/foundInExcavation',
        'excav:foundInExcavation',
        "https://purl.org/megalod/$currentItemSetId/excavation/foundInExcavation"
    ];
    
    foreach ($excavationUris as $excavUri) {
        if (isset($rdfData[$encounterUri][$excavUri])) {
            foreach ($rdfData[$encounterUri][$excavUri] as $excObj) {
                if ($excObj['type'] === 'uri') {
                    $excavationId = $this->extractContextDisplayValue($rdfData, $excObj['value']) ?: 
                                   $this->extractIdentifierFromUriStructure($excObj['value']) ?: 
                                   $currentItemSetId;
                    $encounterInfo[] = 'Excavation: ' . $excavationId;
                    error_log("Added excavation to encounter: $excavationId", 3, OMEKA_PATH . '/logs/arrowhead-enhanced.log');
                }
            }
            break;
        }
    }
    
    // Extract context reference  
    $contextUris = [
        'https://purl.org/megalod/ms/excavation/foundInContext',
        'excav:foundInContext',
        "https://purl.org/megalod/$currentItemSetId/excavation/foundInContext"
    ];
    
    foreach ($contextUris as $ctxUri) {
        if (isset($rdfData[$encounterUri][$ctxUri])) {
            foreach ($rdfData[$encounterUri][$ctxUri] as $ctxObj) {
                if ($ctxObj['type'] === 'uri') {
                    $contextId = $this->extractContextDisplayValue($rdfData, $ctxObj['value']) ?: 
                                $this->extractIdentifierFromUriStructure($ctxObj['value']);
                    if ($contextId) {
                        $encounterInfo[] = 'Context: ' . $contextId;
                        error_log("Added context to encounter: $contextId", 3, OMEKA_PATH . '/logs/arrowhead-enhanced.log');
                    }
                }
            }
            break;
        }
    }
    
    // Extract SVU reference
    $svuUris = [
        'https://purl.org/megalod/ms/excavation/foundInSVU',
        'excav:foundInSVU', 
        "https://purl.org/megalod/$currentItemSetId/excavation/foundInSVU"
    ];
    
    foreach ($svuUris as $svuUri) {
        if (isset($rdfData[$encounterUri][$svuUri])) {
            foreach ($rdfData[$encounterUri][$svuUri] as $svuObj) {
                if ($svuObj['type'] === 'uri') {
                    $svuId = $this->extractContextDisplayValue($rdfData, $svuObj['value']) ?: 
                            $this->extractIdentifierFromUriStructure($svuObj['value']);
                    if ($svuId) {
                        $encounterInfo[] = 'SVU: ' . $svuId;
                        error_log("Added SVU to encounter: $svuId", 3, OMEKA_PATH . '/logs/arrowhead-enhanced.log');
                    }
                }
            }
            break;
        }
    }
    
    // Combine all encounter information
    if (!empty($encounterInfo)) {
        if (!isset($itemData['Encounter Event Details'])) {
            $itemData['Encounter Event Details'] = [];
        }
        
        $itemData['Encounter Event Details'][] = [
            'type' => 'literal',
            'property_id' => 4,
            '@value' => implode(' | ', $encounterInfo)
        ];
        
        error_log("Added complete encounter event: " . implode(' | ', $encounterInfo), 3, OMEKA_PATH . '/logs/arrowhead-enhanced.log');
    }
}

/**
 * ENHANCED: Extract context display value with better fallbacks
 */
private function extractContextDisplayValue($rdfData, $contextUri) {
    error_log("Extracting display value for context: $contextUri", 3, OMEKA_PATH . '/logs/context-extraction.log');
    
    // Strategy 1: Get identifier from the resource itself
    if (isset($rdfData[$contextUri])) {
        $resource = $rdfData[$contextUri];
        
        // Try dcterms:identifier
        if (isset($resource['http://purl.org/dc/terms/identifier'])) {
            foreach ($resource['http://purl.org/dc/terms/identifier'] as $idObj) {
                if ($idObj['type'] === 'literal') {
                    error_log("Found identifier: {$idObj['value']}", 3, OMEKA_PATH . '/logs/context-extraction.log');
                    return $idObj['value'];
                }
            }
        }
        
        // Try informationName for locations
        if (isset($resource['http://dbpedia.org/ontology/informationName'])) {
            foreach ($resource['http://dbpedia.org/ontology/informationName'] as $nameObj) {
                if ($nameObj['type'] === 'literal') {
                    error_log("Found informationName: {$nameObj['value']}", 3, OMEKA_PATH . '/logs/context-extraction.log');
                    return $nameObj['value'];
                }
            }
        }
        
        // Try rdfs:label
        if (isset($resource['http://www.w3.org/2000/01/rdf-schema#label'])) {
            foreach ($resource['http://www.w3.org/2000/01/rdf-schema#label'] as $labelObj) {
                if ($labelObj['type'] === 'literal') {
                    error_log("Found label: {$labelObj['value']}", 3, OMEKA_PATH . '/logs/context-extraction.log');
                    return $labelObj['value'];
                }
            }
        }
    }
    
    // Strategy 2: Extract from URI structure
    $uriValue = $this->extractIdentifierFromUriStructure($contextUri);
    if ($uriValue) {
        error_log("Extracted from URI structure: $uriValue", 3, OMEKA_PATH . '/logs/context-extraction.log');
        return $uriValue;
    }
    
    // Strategy 3: Handle special cases
    if (strpos($contextUri, '/location/') !== false) {
        return 'Excavation Location';
    }
    
    if (preg_match('/\/(\d+)$/', $contextUri, $matches)) {
        error_log("Using item set ID: {$matches[1]}", 3, OMEKA_PATH . '/logs/context-extraction.log');
        return $matches[1]; // Return the item set ID
    }
    
    error_log("No display value found for: $contextUri", 3, OMEKA_PATH . '/logs/context-extraction.log');
    return null;
}

/**
 * NEW: Extract coordinate data with proper import from related resources
 */
private function extractCoordinateDataEnhanced($rdfData, $subject, &$itemData, $currentItemSetId) {
    error_log('=== EXTRACTING ENHANCED COORDINATE DATA ===', 3, OMEKA_PATH . '/logs/arrowhead-enhanced.log');
    
    // Strategy 1: Get coordinates from hasCoordinatesInSquare
    $this->extractCoordinateData($rdfData, $subject, $itemData, $currentItemSetId);
    
    // Strategy 2: Also try to get coordinates from related excavation/location
    $locationUris = [
        'https://purl.org/megalod/ms/excavation/foundInLocation',
        'excav:foundInLocation',
        "https://purl.org/megalod/$currentItemSetId/excavation/foundInLocation"
    ];
    
    foreach ($locationUris as $locUri) {
        if (isset($rdfData[$subject][$locUri])) {
            foreach ($rdfData[$subject][$locUri] as $locObj) {
                if ($locObj['type'] === 'uri' && isset($rdfData[$locObj['value']])) {
                    $locationUri = $locObj['value'];
                    error_log("Processing location for coordinates: $locationUri", 3, OMEKA_PATH . '/logs/arrowhead-enhanced.log');
                    
                    // Extract GPS coordinates from location
                    $this->extractGPSFromLocation($rdfData, $locationUri, $itemData);
                }
            }
            break;
        }
    }
}

/**
 * NEW: Extract GPS coordinates from location resource
 */
private function extractGPSFromLocation($rdfData, $locationUri, &$itemData) {
    if (!isset($rdfData[$locationUri])) {
        return;
    }
    
    $location = $rdfData[$locationUri];
    $gpsCoords = [];
    
    // Extract latitude
    if (isset($location['http://www.w3.org/2003/01/geo/wgs84_pos#lat'])) {
        foreach ($location['http://www.w3.org/2003/01/geo/wgs84_pos#lat'] as $latObj) {
            if ($latObj['type'] === 'literal') {
                $gpsCoords[] = 'Lat: ' . $latObj['value'];
            }
        }
    }
    
    // Extract longitude  
    if (isset($location['http://www.w3.org/2003/01/geo/wgs84_pos#long'])) {
        foreach ($location['http://www.w3.org/2003/01/geo/wgs84_pos#long'] as $longObj) {
            if ($longObj['type'] === 'literal') {
                $gpsCoords[] = 'Long: ' . $longObj['value'];
            }
        }
    }
    
    if (!empty($gpsCoords)) {
        if (!isset($itemData['GPS Coordinates'])) {
            $itemData['GPS Coordinates'] = [];
        }
        
        $itemData['GPS Coordinates'][] = [
            'type' => 'literal',
            'property_id' => 7670,
            '@value' => implode(', ', $gpsCoords)
        ];
        
        error_log("Added GPS coordinates from location: " . implode(', ', $gpsCoords), 3, OMEKA_PATH . '/logs/arrowhead-enhanced.log');
    }
}


/**
 * Extract media resources (images, 3D models, etc.)
 */
private function extractMediaResources($rdfData, $subject, &$itemData) {
    error_log('=== EXTRACTING MEDIA RESOURCES ===', 3, OMEKA_PATH . '/logs/arrowhead-enhanced.log');
    
    $mediaUris = [
        'http://www.europeana.eu/schemas/edm/Webresource',
        'edm:Webresource'
    ];
    
    foreach ($mediaUris as $uri) {
        if (isset($rdfData[$subject][$uri])) {
            if (!isset($itemData['Web Resources'])) {
                $itemData['Web Resources'] = [];
            }
            
            foreach ($rdfData[$subject][$uri] as $mediaObj) {
                if ($mediaObj['type'] === 'uri') {
                    $itemData['Web Resources'][] = [
                        'type' => 'uri',
                        'property_id' => 7677,
                        '@id' => $mediaObj['value'],
                        'o:label' => basename($mediaObj['value'])
                    ];
                    
                    error_log("Added web resource: {$mediaObj['value']}", 3, OMEKA_PATH . '/logs/arrowhead-enhanced.log');
                }
            }
            break;
        }
    }
}


                    
private function getCurrentItemSetContext() {
    // This should return the current item set ID being processed
    // You might need to store this in a class property during processing
    return $this->currentProcessingItemSetId ?? null;
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


private function processMorphologyData($rdfData, $subject, &$itemData) {
    // Try both original and normalized property URIs
    $morphologyUris = [
        'https://purl.org/megalod/ms/ah/hasMorphology',
        'ah:hasMorphology'
    ];
    
    // Add additional patterns with item set ID if available
    $itemSetId = $this->getCurrentItemSetContext();
    if ($itemSetId) {
        $morphologyUris[] = "https://purl.org/megalod/$itemSetId/ah/hasMorphology";
    }
    
    // Try all possible URI patterns
    foreach ($morphologyUris as $morphologyUri) {
        if (isset($rdfData[$subject][$morphologyUri])) {
            foreach ($rdfData[$subject][$morphologyUri] as $morphObj) {
                if ($morphObj['type'] === 'uri' && isset($rdfData[$morphObj['value']])) {
                    $morphUri = $morphObj['value'];
                    
                    // Try multiple property name patterns for each property
                    $this->extractMorphologyProperty($rdfData, $morphUri, 'ah:point', 'point', 'Point Definition', 7653, $itemData);
                    $this->extractMorphologyProperty($rdfData, $morphUri, 'ah:body', 'body', 'Body Symmetry', 7654, $itemData);
                    $this->extractMorphologyProperty($rdfData, $morphUri, 'ah:base', 'base', 'Base Type', 7655, $itemData);
                    
                    return;
                }
            }
        }
    }
}



/**
 * Enhanced Encounter Event Management for Archaeological Data
 * Add these methods to your IndexController class
 */

/**
 * Pre-upload validation and encounter event creation
 */
private function processArrowheadWithEncounterValidation($ttlData, $itemSetId) {
    error_log('=== ENHANCED ARROWHEAD PROCESSING WITH ENCOUNTER VALIDATION ===', 3, OMEKA_PATH . '/logs/encounter-validation.log');
    
    // 1. Extract arrowhead context references from TTL
    $arrowheadContext = $this->extractArrowheadContextFromTtl($ttlData);
    
    // 2. Validate context relationships exist in item set
    $validationResult = $this->validateContextRelationships($arrowheadContext, $itemSetId);
    
    if (!$validationResult['valid']) {
        return [
            'success' => false,
            'error' => $validationResult['error'],
            'details' => $validationResult['details']
        ];
    }
    
    // 3. Find or create encounter event
    $encounterEvent = $this->findOrCreateEncounterEvent($arrowheadContext, $itemSetId);
    
    // 4. Update TTL with encounter event reference
    $enhancedTtl = $this->addEncounterEventToTtl($ttlData, $encounterEvent, $itemSetId);
    
    // 5. Proceed with regular upload
    return [
        'success' => true,
        'ttl' => $enhancedTtl,
        'encounter_event' => $encounterEvent
    ];
}

/**
 * Extract context information from arrowhead TTL
 */
private function extractArrowheadContextFromTtl($ttlData) {
    $context = [
        'excavation' => null,
        'location' => null,
        'square' => null,
        'context' => null,
        'svu' => null,
        'date' => null,
        'item_identifier' => null
    ];
    
    // Extract item identifier
    if (preg_match('/dct:identifier\s+"([^"]+)"/i', $ttlData, $matches)) {
        $context['item_identifier'] = $matches[1];
    }

    // Extract date
    if (preg_match('/dct:date\s+"([^"]+)"/i', $ttlData, $matches)) {
        $context['date'] = $matches[1];
        error_log("Found date in TTL: {$context['date']}", 3, OMEKA_PATH . '/logs/encounter-validation.log');
    }
    
    
    // Extract context reference
    if (preg_match('/excav:foundInContext\s+<([^>]+)>/i', $ttlData, $matches)) {
        $contextUri = $matches[1];
        $context['context'] = $this->extractIdentifierFromUri($contextUri);
        error_log("Found context reference: {$context['context']}", 3, OMEKA_PATH . '/logs/encounter-validation.log');
    }
    
    // Extract SVU reference
    if (preg_match('/excav:foundInSVU\s+<([^>]+)>/i', $ttlData, $matches)) {
        $svuUri = $matches[1];
        $context['svu'] = $this->extractIdentifierFromUri($svuUri);
        error_log("Found SVU reference: {$context['svu']}", 3, OMEKA_PATH . '/logs/encounter-validation.log');
    }
    
    // Extract square reference
    if (preg_match('/excav:foundInSquare\s+<([^>]+)>/i', $ttlData, $matches)) {
        $squareUri = $matches[1];
        $context['square'] = $this->extractIdentifierFromUri($squareUri);
        error_log("Found square reference: {$context['square']}", 3, OMEKA_PATH . '/logs/encounter-validation.log');
    }
    
    // Extract location reference
    if (preg_match('/excav:foundInLocation\s+<([^>]+)>/i', $ttlData, $matches)) {
        $locationUri = $matches[1];
        $context['location'] = $this->extractIdentifierFromUri($locationUri);
        error_log("Found location reference: {$context['location']}", 3, OMEKA_PATH . '/logs/encounter-validation.log');
    }
    
    // Extract excavation reference (usually the base URI)
    if (preg_match('/excav:foundInExcavation\s+<([^>]+)>/i', $ttlData, $matches)) {
        $excavationUri = $matches[1];
        $context['excavation'] = $this->extractIdentifierFromUri($excavationUri);
        error_log("Found excavation reference: {$context['excavation']}", 3, OMEKA_PATH . '/logs/encounter-validation.log');
    }
    
    return $context;
}

/**
 * Validate that context relationships exist in the excavation
 */
private function validateContextRelationships($arrowheadContext, $itemSetId) {
    error_log("Validating context relationships for item set: $itemSetId", 3, OMEKA_PATH . '/logs/encounter-validation.log');
    
    // Get excavation data from GraphDB
    $excavationRelationships = $this->getExcavationRelationshipsFromGraphDB($itemSetId);
    
    $errors = [];
    $details = [];
    
    // Check if context exists
    if ($arrowheadContext['context'] && !in_array($arrowheadContext['context'], $excavationRelationships['contexts'])) {
        $errors[] = "Context '{$arrowheadContext['context']}' does not exist in this excavation";
        $details['available_contexts'] = $excavationRelationships['contexts'];
    }
    
    // Check if SVU exists
    if ($arrowheadContext['svu'] && !in_array($arrowheadContext['svu'], $excavationRelationships['svus'])) {
        $errors[] = "SVU '{$arrowheadContext['svu']}' does not exist in this excavation";
        $details['available_svus'] = $excavationRelationships['svus'];
    }
    
    // Check if square exists
    if ($arrowheadContext['square'] && !in_array($arrowheadContext['square'], $excavationRelationships['squares'])) {
        $errors[] = "Square '{$arrowheadContext['square']}' does not exist in this excavation";
        $details['available_squares'] = $excavationRelationships['squares'];
    }
    
    // CRITICAL: Check if Context-SVU relationship exists
    if ($arrowheadContext['context'] && $arrowheadContext['svu']) {
        $relationshipExists = false;
        foreach ($excavationRelationships['context_svu_links'] as $link) {
            if ($link['context'] === $arrowheadContext['context'] && $link['svu'] === $arrowheadContext['svu']) {
                $relationshipExists = true;
                break;
            }
        }
        
        if (!$relationshipExists) {
            $errors[] = "Invalid relationship: Context '{$arrowheadContext['context']}' is not linked to SVU '{$arrowheadContext['svu']}' in this excavation";
            $details['valid_relationships'] = $excavationRelationships['context_svu_links'];
        }
    }
    
    return [
        'valid' => empty($errors),
        'error' => implode('; ', $errors),
        'details' => $details
    ];
}

/**
 * Get excavation relationships from GraphDB
 */
private function getExcavationRelationshipsFromGraphDB($itemSetId) {
    $graphUri = $this->baseDataGraphUri . $itemSetId . "/";
    error_log("Querying excavation relationships from GraphDB: $graphUri", 3, OMEKA_PATH . '/logs/encounter-validation.log');
    $query = "
PREFIX excav: <https://purl.org/megalod/ms/excavation/>
PREFIX dct: <http://purl.org/dc/terms/>

SELECT ?contextId ?svuId ?squareId ?hasRelationship
WHERE {
  GRAPH <$graphUri> {
    # Get all contexts
    ?context a excav:Context ;
             dct:identifier ?contextId .
    
    # Get all SVUs
    ?svu a excav:StratigraphicVolumeUnit ;
         dct:identifier ?svuId .
    
    # Get all squares
    ?square a excav:Square ;
            dct:identifier ?squareId .
    
    # Check for context-SVU relationships
    OPTIONAL {
      ?context excav:hasSVU ?svu .
      BIND(true AS ?hasRelationship)
    }
  }
}
";
    
    try {
        $client = new \Laminas\Http\Client();
        $client->setUri($this->graphdbQueryEndpoint);
        $client->setMethod('POST');
        $client->setHeaders([
            'Content-Type' => 'application/sparql-query',
            'Accept' => 'application/sparql-results+json'
        ]);
        $client->setRawBody($query);
        
        $response = $client->send();
        
        if ($response->isSuccess()) {
            $results = json_decode($response->getBody(), true);
            
            $contexts = [];
            $svus = [];
            $squares = [];
            $contextSvuLinks = [];
            
            foreach ($results['results']['bindings'] as $binding) {
                if (isset($binding['contextId'])) {
                    $contexts[] = $binding['contextId']['value'];
                }
                if (isset($binding['svuId'])) {
                    $svus[] = $binding['svuId']['value'];
                }
                if (isset($binding['squareId'])) {
                    $squares[] = $binding['squareId']['value'];
                }
                if (isset($binding['hasRelationship']) && $binding['hasRelationship']['value'] === 'true') {
                    $contextSvuLinks[] = [
                        'context' => $binding['contextId']['value'],
                        'svu' => $binding['svuId']['value']
                    ];
                }
            }
            
            return [
                'contexts' => array_unique($contexts),
                'svus' => array_unique($svus),
                'squares' => array_unique($squares),
                'context_svu_links' => $contextSvuLinks
            ];
        }
    } catch (\Exception $e) {
        error_log("Error querying excavation relationships: " . $e->getMessage(), 3, OMEKA_PATH . '/logs/encounter-validation.log');
    }
    
    return [
        'contexts' => [],
        'svus' => [],
        'squares' => [],
        'context_svu_links' => []
    ];
}

/**
 * Find or create encounter event
 */
private function findOrCreateEncounterEvent($arrowheadContext, $itemSetId) {
    error_log("Finding or creating encounter event", 3, OMEKA_PATH . '/logs/encounter-creation.log');
    
    // Generate encounter signature for grouping
    $encounterSignature = $this->generateEncounterSignature($arrowheadContext);
    
    // Check if encounter event already exists
    $existingEncounter = $this->findExistingEncounterEvent($encounterSignature, $itemSetId);
    
    if ($existingEncounter) {
        error_log("Found existing encounter event: {$existingEncounter['id']}", 3, OMEKA_PATH . '/logs/encounter-creation.log');
        return $existingEncounter;
    }
    
    // Create new encounter event
    $newEncounter = $this->createNewEncounterEvent($arrowheadContext, $itemSetId, $encounterSignature);
    error_log("Created new encounter event: {$newEncounter['id']}", 3, OMEKA_PATH . '/logs/encounter-creation.log');
    
    return $newEncounter;
}

/**
 * Generate encounter signature for grouping similar finds
 */
private function generateEncounterSignature($context) {
    // Group by: excavation + context + svu + date
    $signature = [
        'excavation' => $context['excavation'] ?: 'unknown',
        'context' => $context['context'] ?: 'no-context',
        'svu' => $context['svu'] ?: 'no-svu',
        'date' => $context['date'],
        'square' => $context['square'] ?: 'no-square'
    ];
    
    return md5(json_encode($signature));
}

/**
 * Find existing encounter event by signature
 */
private function findExistingEncounterEvent($signature, $itemSetId) {
    try {
        // Search for encounter events in this item set with matching signature
        $searchParams = [
            'resource_class_id' => $this->getEncounterEventResourceClassId(),
            'item_set_id' => $itemSetId,
            'property' => [
                [
                    'property' => $this->getEncounterSignaturePropertyId(),
                    'type' => 'eq',
                    'text' => $signature
                ]
            ]
        ];
        
        $response = $this->api()->search('items', $searchParams);
        $encounters = $response->getContent();
        
        if (!empty($encounters)) {
            $encounter = $encounters[0];
            return [
                'id' => $encounter->id(),
                'signature' => $signature,
                'omeka_id' => $encounter->id()
            ];
        }
    } catch (\Exception $e) {
        error_log("Error finding existing encounter: " . $e->getMessage(), 3, OMEKA_PATH . '/logs/encounter-creation.log');
    }
    
    return null;
}

/**
 * Create new encounter event in Omeka
 */
private function createNewEncounterEvent($context, $itemSetId, $signature) {
    $encounterData = [
        'o:resource_class' => ['o:id' => $this->getEncounterEventResourceClassId()],
        'o:item_set' => [['o:id' => $itemSetId]],
        'dcterms:title' => [
            [
                'type' => 'literal',
                'property_id' => 1,
                '@value' => $this->generateEncounterTitle($context)
            ]
        ],
        'dcterms:description' => [
            [
                'type' => 'literal',
                'property_id' => 4,
                '@value' => $this->generateEncounterDescription($context)
            ]
        ],
        'dcterms:date' => [
            [
                'type' => 'literal',
                'property_id' => 7,
                '@value' => $context['date']
            ]
        ],
        // Store signature for future lookups
        'dcterms:identifier' => [
            [
                'type' => 'literal',
                'property_id' => 10,
                '@value' => $signature
            ]
        ]
    ];
    
    // Add context references
    if ($context['context']) {
        $encounterData['excav:foundInContext'] = [
            [
                'type' => 'literal',
                'property_id' => 7672,
                '@value' => $context['context']
            ]
        ];
    }
    
    if ($context['svu']) {
        $encounterData['excav:foundInSVU'] = [
            [
                'type' => 'literal',
                'property_id' => 7671,
                '@value' => $context['svu']
            ]
        ];
    }
    
    try {
        $response = $this->api()->create('items', $encounterData);
        $encounter = $response->getContent();
        
        return [
            'id' => $encounter->id(),
            'signature' => $signature,
            'omeka_id' => $encounter->id()
        ];
    } catch (\Exception $e) {
        error_log("Error creating encounter event: " . $e->getMessage(), 3, OMEKA_PATH . '/logs/encounter-creation.log');
        throw $e;
    }
}

/**
 * Add encounter event reference to TTL with complete context data
 */
private function addEncounterEventToTtl($ttlData, $encounterEvent, $itemSetId) {
    $encounterUri = "https://purl.org/megalod/$itemSetId/encounter/encounter-{$encounterEvent['omeka_id']}";
    
    // Extract item identifier and context from TTL
    $itemIdentifier = $this->extractItemIdentifierFromTtl($ttlData);
    $arrowheadContext = $this->extractArrowheadContextFromTtl($ttlData);
    
    // 1. Add encounter reference to the arrowhead item
    $encounterTriple = "    crmsci:O19i_was_object_encountered_through <$encounterUri> ;\n";
    
    // FIXED: Insert after the dct:identifier line, not at the end
    $pattern = '/(dct:identifier\s+"[^"]+"\^\^xsd:literal\s*;)(\s*)/';
    $replacement = "$1\n$encounterTriple$2";
    
    $enhancedTtl = preg_replace($pattern, $replacement, $ttlData, 1);
    
    // 2. Add complete encounter event definition
    $encounterDefinition = "\n\n# =========== ENCOUNTER EVENT ===========\n\n";
    $encounterDefinition .= "<$encounterUri> a excav:EncounterEvent ;\n";
    
    // Add date
    $encounterDefinition .= "    dct:date \"" . $arrowheadContext['date'] . "\"^^xsd:literal ;\n";
    
    // FIXED: Use correct arrowhead URI format (not /item/ path)
    $encounterDefinition .= "    crmsci:O19_encountered_object <https://purl.org/megalod/$itemSetId/$itemIdentifier> ;\n";
    
    // Add excavation reference
    if ($arrowheadContext['excavation']) {
        $encounterDefinition .= "    excav:foundInExcavation <https://purl.org/megalod/$itemSetId> ;\n";
    }
    
    // Add context reference
    if ($arrowheadContext['context']) {
        $encounterDefinition .= "    excav:foundInContext <https://purl.org/megalod/$itemSetId/context/{$arrowheadContext['context']}> ;\n";
    }
    
    // Add SVU reference
    if ($arrowheadContext['svu']) {
        $encounterDefinition .= "    excav:foundInSVU <https://purl.org/megalod/$itemSetId/svu/{$arrowheadContext['svu']}> ;\n";
    }
    
    // Add location reference
    if ($arrowheadContext['location']) {
        $encounterDefinition .= "    excav:foundInLocation <https://purl.org/megalod/$itemSetId/location/{$arrowheadContext['location']}> ;\n";
    }
    
    // Add square reference
    if ($arrowheadContext['square']) {
        $encounterDefinition .= "    excav:foundInSquare <https://purl.org/megalod/$itemSetId/square/{$arrowheadContext['square']}> ;\n";
    }
    
    // Close the encounter event definition
    $encounterDefinition .= "    .\n";
    
    error_log("Generated encounter event TTL:\n$encounterDefinition", 3, OMEKA_PATH . '/logs/encounter-ttl.log');
    
    return $enhancedTtl . $encounterDefinition;
}
/**
 * Generate encounter title
 */
private function generateEncounterTitle($context) {
    $parts = [];
    
    if ($context['date']) {
        $parts[] = "Excavation Session " . $context['date'];
    }
    
    if ($context['context'] && $context['svu']) {
        $parts[] = "Context {$context['context']}, SVU {$context['svu']}";
    } elseif ($context['context']) {
        $parts[] = "Context {$context['context']}";
    } elseif ($context['svu']) {
        $parts[] = "SVU {$context['svu']}";
    }
    
    if ($context['square']) {
        $parts[] = "Square {$context['square']}";
    }
    
    return implode(' - ', $parts) ?: 'Archaeological Encounter Event';
}

/**
 * Generate encounter description
 */
private function generateEncounterDescription($context) {
    $description = "Archaeological encounter event documenting finds";
    
    if ($context['date']) {
        $description .= " from " . $context['date'];
    }
    
    $contextParts = [];
    if ($context['context']) $contextParts[] = "context {$context['context']}";
    if ($context['svu']) $contextParts[] = "stratigraphic unit {$context['svu']}";
    if ($context['square']) $contextParts[] = "square {$context['square']}";
    
    if (!empty($contextParts)) {
        $description .= " in " . implode(', ', $contextParts);
    }
    
    return $description . ".";
}

/**
 * Extract identifier from TTL data
 */
private function extractItemIdentifierFromTtl($ttlData) {
    if (preg_match('/dct:identifier\s+"([^"]+)"/i', $ttlData, $matches)) {
        return $matches[1];
    }
    return 'unknown-item';
}

/**
 * Helper methods for resource class and property IDs
 */
private function getEncounterEventResourceClassId() {
    // You'll need to create this resource class in Omeka or return the appropriate ID
    return 123; // Replace with actual resource class ID for EncounterEvent
}

private function getEncounterSignaturePropertyId() {
    // Use dcterms:identifier or create a custom property
    return 10; // dcterms:identifier
}

/**
 * Extract identifier from URI
 */
private function extractIdentifierFromUri($uri) {
    // Extract the last part of the URI
    $parts = explode('/', $uri);
    return end($parts);
}









/**
 * Helper to extract a specific morphology property
 */
private function extractMorphologyProperty($rdfData, $morphUri, $propertyName, $shortName, $label, $propertyId, &$itemData) {
    // Try both standard and shorthand property names
    $propertyVariants = [
        $propertyName,
        "https://purl.org/megalod/ms/ah/$shortName"
    ];
    
    // Add item set specific variant if available
    $itemSetId = $this->getCurrentItemSetContext();
    if ($itemSetId) {
        $propertyVariants[] = "https://purl.org/megalod/$itemSetId/ah/$shortName";
    }
    
    foreach ($propertyVariants as $property) {
        if (isset($rdfData[$morphUri][$property])) {
            error_log("Found morphology property: $property in $morphUri", 3, OMEKA_PATH . '/logs/morphology-debug.log');
            
            if (!isset($itemData[$label])) {
                $itemData[$label] = [];
            }
            
            foreach ($rdfData[$morphUri][$property] as $propObj) {
                if ($propObj['type'] === 'literal') {
                    // Handle boolean values
                    if ($propObj['value'] === 'true' || $propObj['value'] === 'false') {
                        $displayValue = ($propObj['value'] === 'true') ? 'True' : 'False';
                    } else {
                        $displayValue = $propObj['value'];
                    }
                    
                    $itemData[$label][] = [
                        'type' => 'literal',
                        'property_id' => $propertyId,
                        '@value' => $displayValue
                    ];
                    
                    error_log("Added morphology $label: $displayValue", 3, OMEKA_PATH . '/logs/morphology-debug.log');
                } else if ($propObj['type'] === 'uri') {
                    // For URI values like base type, extract the meaningful part
                    $parts = explode('/', $propObj['value']);
                    $value = ucfirst(end($parts));
                    
                    $itemData[$label][] = [
                        'type' => 'literal',
                        'property_id' => $propertyId,
                        '@value' => $value
                    ];
                    
                    error_log("Added morphology $label (from URI): $value", 3, OMEKA_PATH . '/logs/morphology-debug.log');
                }
            }
            
            return true; // Found and processed this property
        }
    }
    
    error_log("Morphology property not found: $propertyName", 3, OMEKA_PATH . '/logs/morphology-debug.log');
    return false;
}

/**
 * Extract SVU data from RDF
 */
private function extractSvuData($rdfData, $svuUri) {
    $data = [
        'name' => null,
        'description' => null
    ];
    
    // Extract identifier as name
    if (isset($rdfData[$svuUri]['http://purl.org/dc/terms/identifier'])) {
        foreach ($rdfData[$svuUri]['http://purl.org/dc/terms/identifier'] as $idObj) {
            if ($idObj['type'] === 'literal') {
                $data['name'] = $idObj['value'];
                break;
            }
        }
    }
    
    // Extract description
    if (isset($rdfData[$svuUri]['http://purl.org/dc/terms/description'])) {
        foreach ($rdfData[$svuUri]['http://purl.org/dc/terms/description'] as $descObj) {
            if ($descObj['type'] === 'literal') {
                $data['description'] = $descObj['value'];
                break;
            }
        }
    }
    
    return ($data['name'] || $data['description']) ? $data : null;
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
 * Generate declarations for referenced resources
 * 
 * @param array $uriMappings Map of original URIs to new URIs (key: oldUri, value: newUri)
 * @param int $itemSetId The Omeka S item set ID
 * @return string TTL declarations for referenced resources
 */
private function generateResourceDeclarations($uriMappings, $itemSetId) {
    $declarations = '';
    $declaredResources = []; // To avoid duplicate declarations for the same resource
    
    error_log("generateResourceDeclarations: Processing " . count($uriMappings) . " URI mappings for itemSetId: $itemSetId", 3, OMEKA_PATH . '/logs/resource-declarations.log');

    // The pattern should match the structure of the *new* URIs
    // e.g., <https://purl.org/megalod/ITEM_SET_ID/resourceType/resourceIdentifier>
    $newUriPattern = '/<https:\/\/purl\.org\/megalod\/' . preg_quote((string)$itemSetId, '/') . '\/([^\/]+)\/([^>]+)>/';

    foreach ($uriMappings as $oldUri => $newUri) {
        // We need to parse the $newUri to get the resourceType and resourceId
        // as these declarations are for the *normalized* resources.
        if (preg_match($newUriPattern, $newUri, $match)) {
            $resourceType = $match[1]; // e.g., "context", "svu", "square", "item"
            $resourceId = $match[2];   // e.g., "CV-001", "SVU-1", "A1", "AH-123"
            
            // Create a unique key for this resource to avoid duplicate declarations
            $resourceKey = $resourceType . '/' . $resourceId;

            if (isset($declaredResources[$resourceKey])) {
                error_log("generateResourceDeclarations: Skipping already declared resource: $newUri", 3, OMEKA_PATH . '/logs/resource-declarations.log');
                continue;
            }
            
            // Skip controlled vocabulary terms (KOS)
            if (strpos($newUri, '/kos/') !== false) {
                error_log("generateResourceDeclarations: Skipping KOS URI: $newUri", 3, OMEKA_PATH . '/logs/resource-declarations.log');
                continue;
            }

            $declarationMade = false;
            // Add declaration based on resource type using the $newUri
            switch (strtolower($resourceType)) {
                case 'excavation':
                    // Excavation itself should ideally be declared where it's fully defined,
                    // but if referenced and needs a basic declaration:
                    $declarations .= "$newUri a excav:Excavation ;\n    dct:identifier \"$resourceId\"^^xsd:string .\n\n";
                    $declarationMade = true;
                    break;
                case 'context':
                    $declarations .= "$newUri a excav:Context ;\n    dct:identifier \"$resourceId\"^^xsd:string .\n\n";
                    $declarationMade = true;
                    break;
                case 'svu':
                case 'stratigraphicvolumeunit': // Allow for full name
                    $declarations .= "$newUri a excav:StratigraphicVolumeUnit ;\n    dct:identifier \"$resourceId\"^^xsd:string .\n\n";
                    $declarationMade = true;
                    break;
                case 'square':
                    $declarations .= "$newUri a excav:Square ;\n    dct:identifier \"$resourceId\"^^xsd:string .\n\n";
                    $declarationMade = true;
                    break;
                case 'item': // For items like arrowheads
                    $declarations .= "$newUri a excav:Item ;\n    dct:identifier \"$resourceId\"^^xsd:string .\n\n";
                    // Potentially add ah:Arrowhead if it's specifically an arrowhead,
                    // but excav:Item is a good general declaration.
                    // $declarations .= "$newUri a excav:Item, ah:Arrowhead ;\n    dct:identifier \"$resourceId\"^^xsd:string .\n\n";
                    $declarationMade = true;
                    break;
                case 'location':
                     $declarations .= "$newUri a excav:Location ;\n    rdfs:label \"Location $resourceId\"^^xsd:string .\n\n"; // Basic label
                     $declarationMade = true;
                     break;
                // Add other cases as needed, e.g., 'morphology', 'chipping', 'typometryvalue'
                // However, these are often complex objects defined elsewhere, not just simple declarations.
                default:
                    error_log("generateResourceDeclarations: No specific declaration rule for resource type '$resourceType' in URI: $newUri", 3, OMEKA_PATH . '/logs/resource-declarations.log');
                    break;
            }
            
            if ($declarationMade) {
                $declaredResources[$resourceKey] = true;
                error_log("generateResourceDeclarations: Added declaration for: $newUri (Type: $resourceType, ID: $resourceId)", 3, OMEKA_PATH . '/logs/resource-declarations.log');
            }

        } else {
            error_log("generateResourceDeclarations: Could not parse new URI with pattern '$newUriPattern': $newUri (Original old URI: $oldUri)", 3, OMEKA_PATH . '/logs/resource-declarations.log');
        }
    }
    
    if (!empty($declarations)) {
        error_log("generateResourceDeclarations: Generated declarations:\n$declarations", 3, OMEKA_PATH . '/logs/resource-declarations.log');
    } else {
        error_log("generateResourceDeclarations: No declarations generated for itemSetId: $itemSetId", 3, OMEKA_PATH . '/logs/resource-declarations.log');
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
 * FIXED: Enhanced extractResourceIdentifier method to get actual meaningful identifiers
 */
private function extractResourceIdentifier($rdfData, $resourceUri) {
    error_log("=== IDENTIFIER EXTRACTION DEBUG ===", 3, OMEKA_PATH . '/logs/identifier-debug.log');
    error_log("Extracting identifier from URI: $resourceUri", 3, OMEKA_PATH . '/logs/identifier-debug.log');
    
    if (!isset($rdfData[$resourceUri])) {
        error_log("Resource URI not found in RDF data: $resourceUri", 3, OMEKA_PATH . '/logs/identifier-debug.log');
        
        // FALLBACK 1: Try to extract meaningful identifier from URI structure
        $identifier = $this->extractIdentifierFromUriStructure($resourceUri);
        if ($identifier) {
            error_log("Extracted identifier from URI structure: $identifier", 3, OMEKA_PATH . '/logs/identifier-debug.log');
            return $identifier;
        }
        
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
                    error_log("Found identifier '$idObj[value]' for resource $resourceUri using predicate $predicate", 3, OMEKA_PATH . '/logs/identifier-debug.log');
                    return $idObj['value'];
                }
            }
        }
    }
    
    // FALLBACK 2: Try to extract from URI structure with better patterns
    $identifier = $this->extractIdentifierFromUriStructure($resourceUri);
    if ($identifier) {
        error_log("Extracted identifier from URI structure: $identifier", 3, OMEKA_PATH . '/logs/identifier-debug.log');
        return $identifier;
    }
    
    error_log("Could not extract identifier from resource: $resourceUri", 3, OMEKA_PATH . '/logs/identifier-debug.log');
    return null;
}

private function getRealLocationUriFromExcavation($itemSetId) {
    error_log("=== GETTING REAL LOCATION URI FOR ITEM SET $itemSetId ===", 3, OMEKA_PATH . '/logs/location-discovery.log');
    
    try {
        // Query GraphDB to find the excavation's location
        $graphUri = $this->baseDataGraphUri . $itemSetId . "/";
        error_log("Graph URI: $graphUri", 3, OMEKA_PATH . '/logs/location-discovery.log');
        
        $query = "
PREFIX excav: <https://purl.org/megalod/ms/excavation/>
PREFIX dul: <http://www.ontologydesignpatterns.org/ont/dul/DUL.owl#>
PREFIX dbo: <http://dbpedia.org/ontology/>

SELECT ?locationUri ?locationName
WHERE {
  GRAPH <$graphUri> {
    ?excavation a excav:Excavation .
    ?excavation dul:hasLocation ?locationUri .
    ?locationUri a excav:Location .
    OPTIONAL {
      ?locationUri dbo:informationName ?locationName .
    }
  }
}
LIMIT 1
";

        error_log("SPARQL Query: $query", 3, OMEKA_PATH . '/logs/location-discovery.log');

        $client = new \Laminas\Http\Client();
        $client->setUri($this->graphdbQueryEndpoint);
        $client->setMethod('POST');
        $client->setHeaders([
            'Content-Type' => 'application/sparql-query',
            'Accept' => 'application/sparql-results+json'
        ]);
        $client->setRawBody($query);
        
        $response = $client->send();
        
        if ($response->isSuccess()) {
            $results = json_decode($response->getBody(), true);
            
            if (isset($results['results']['bindings']) && !empty($results['results']['bindings'])) {
                $binding = $results['results']['bindings'][0];
                $locationUri = $binding['locationUri']['value'];
                $locationName = isset($binding['locationName']) ? $binding['locationName']['value'] : 'Unknown Location';
                
                error_log("✓ Found real location URI: $locationUri (Name: $locationName)", 3, OMEKA_PATH . '/logs/location-discovery.log');
                return $locationUri;
            }
        }
        
        error_log("❌ No location found in GraphDB for item set $itemSetId", 3, OMEKA_PATH . '/logs/location-discovery.log');
        
    } catch (\Exception $e) {
        error_log("❌ Error querying GraphDB for location: " . $e->getMessage(), 3, OMEKA_PATH . '/logs/location-discovery.log');
    }
    
    return null; // No fallbacks - either it exists or it doesn't
}

/**
 * NEW: Extract meaningful identifiers from URI structure
 */
private function extractIdentifierFromUriStructure($resourceUri) {
    error_log("Analyzing URI structure: $resourceUri", 3, OMEKA_PATH . '/logs/identifier-debug.log');
    
    // Pattern 1: https://purl.org/megalod/2043/context/item-2048
    // Should extract the original context identifier, not "item-2048"
    if (preg_match('/\/([^\/]+)\/item-(\d+)$/', $resourceUri, $matches)) {
        $resourceType = $matches[1]; // context, svu, square, etc.
        $itemId = $matches[2];       // 2048, 2051, etc.
        
        error_log("Found item-based URI: type=$resourceType, itemId=$itemId", 3, OMEKA_PATH . '/logs/identifier-debug.log');
        
        // Try to find the actual item in Omeka to get its real identifier
        $realIdentifier = $this->getRealIdentifierFromOmekaItem($itemId);
        if ($realIdentifier) {
            error_log("Found real identifier from Omeka item $itemId: $realIdentifier", 3, OMEKA_PATH . '/logs/identifier-debug.log');
            return $realIdentifier;
        }
        
        // Fallback: generate a reasonable identifier based on type
        switch (strtolower($resourceType)) {
            case 'context':
                return "CV-" . str_pad($itemId % 1000, 3, '0', STR_PAD_LEFT); // CV-001, CV-002, etc.
            case 'svu':
                return "CV-001-" . ($itemId % 10); // CV-001-1, CV-001-2, etc.
            case 'square':
                $letters = ['A', 'B', 'C', 'D'];
                $letter = $letters[($itemId - 1) % 4];
                $number = (($itemId - 1) % 4) + 1;
                return $letter . $number; // A1, A2, B1, B2, etc.
            default:
                return strtoupper($resourceType) . "-" . ($itemId % 1000);
        }
    }
    
    // Pattern 2: https://purl.org/megalod/2043/context/CV-001
    // This should directly extract CV-001
    if (preg_match('/\/([^\/]+)\/([^\/]+)$/', $resourceUri, $matches)) {
        $resourceType = $matches[1];
        $identifier = $matches[2];
        
        // Skip if it looks like "item-XXXX" pattern (already handled above)
        if (!preg_match('/^item-\d+$/', $identifier)) {
            error_log("Found direct identifier URI: type=$resourceType, identifier=$identifier", 3, OMEKA_PATH . '/logs/identifier-debug.log');
            return $identifier;
        }
    }
    
    // Pattern 3: Just extract the last meaningful part
    $parts = explode('/', $resourceUri);
    $lastPart = end($parts);
    
    // If the last part looks like a meaningful identifier, use it
    if (preg_match('/^[A-Za-z0-9-]+$/', $lastPart) && strlen($lastPart) > 1 && !preg_match('/^\d+$/', $lastPart)) {
        error_log("Using last part as identifier: $lastPart", 3, OMEKA_PATH . '/logs/identifier-debug.log');
        return $lastPart;
    }
    
    return null;
}

/**
 * ENHANCED: Get the real identifier from an Omeka item by its ID with better fallbacks
 */
private function getRealIdentifierFromOmekaItem($itemId) {
    try {
        error_log("Looking up real identifier for Omeka item ID: $itemId", 3, OMEKA_PATH . '/logs/identifier-debug.log');
        
        $item = $this->api()->read('items', $itemId)->getContent();
        
        // Strategy 1: Try to get the dcterms:identifier value
        $values = $item->values();
        
        if (isset($values['dcterms:identifier'])) {
            foreach ($values['dcterms:identifier'] as $value) {
                if ($value instanceof \Omeka\Api\Representation\ValueRepresentation) {
                    $identifier = $value->value();
                    error_log("Found real identifier for item $itemId: $identifier", 3, OMEKA_PATH . '/logs/identifier-debug.log');
                    return $identifier;
                }
            }
        }
        
        // Strategy 2: Check other common identifier properties
        $identifierProperties = [
            'dcterms:title',
            'bibo:identifier', 
            'schema:identifier'
        ];
        
        foreach ($identifierProperties as $prop) {
            if (isset($values[$prop])) {
                foreach ($values[$prop] as $value) {
                    if ($value instanceof \Omeka\Api\Representation\ValueRepresentation) {
                        $val = $value->value();
                        // Look for identifier patterns in the value
                        if (preg_match('/\b([A-Z]{1,4}-\d+(?:-\d+)?)\b/', $val, $matches)) {
                            error_log("Found identifier pattern in $prop for item $itemId: {$matches[1]}", 3, OMEKA_PATH . '/logs/identifier-debug.log');
                            return $matches[1];
                        }
                    }
                }
            }
        }
        
        // Strategy 3: Extract identifier patterns from title
        $title = $item->displayTitle();
        error_log("Item $itemId title: $title", 3, OMEKA_PATH . '/logs/identifier-debug.log');
        
        // Look for various identifier patterns
        $patterns = [
            '/\b(CV-\d+-\d+)\b/',     // SVU pattern: CV-001-1
            '/\b(CV-\d+)\b/',         // Context pattern: CV-001
            '/\b([A-Z]\d+)\b/',       // Square pattern: A1, B2
            '/\b(AH-[A-Z0-9]+)\b/',   // Arrowhead pattern: AH-001
            '/\b([A-Z]{2,4}-\d+)\b/', // General pattern: EXC-001
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $title, $matches)) {
                error_log("Extracted identifier from title using pattern $pattern: {$matches[1]}", 3, OMEKA_PATH . '/logs/identifier-debug.log');
                return $matches[1];
            }
        }
        
        // Strategy 4: Generate reasonable identifier based on item type and ID
        $resourceClasses = $item->resourceClass();
        if ($resourceClasses) {
            $className = $resourceClasses->label();
            error_log("Item $itemId resource class: $className", 3, OMEKA_PATH . '/logs/identifier-debug.log');
            
            // Generate identifier based on class type
            switch (strtolower($className)) {
                case 'context':
                    $identifier = "CV-" . str_pad($itemId % 1000, 3, '0', STR_PAD_LEFT);
                    break;
                case 'stratigraphic unit':
                case 'svu':
                    $identifier = "CV-001-" . ($itemId % 10);
                    break;
                case 'square':
                    $letters = ['A', 'B', 'C', 'D'];
                    $letter = $letters[($itemId - 1) % 4];
                    $number = (($itemId - 1) % 4) + 1;
                    $identifier = $letter . $number;
                    break;
                case 'arrowhead':
                    $identifier = "AH-" . str_pad($itemId % 1000, 3, '0', STR_PAD_LEFT);
                    break;
                default:
                    $identifier = "ITEM-$itemId";
                    break;
            }
            
            error_log("Generated identifier based on class '$className': $identifier", 3, OMEKA_PATH . '/logs/identifier-debug.log');
            return $identifier;
        }
        
        // Strategy 5: Last resort - use item ID with a prefix
        $fallbackIdentifier = "ITEM-$itemId";
        error_log("Using fallback identifier: $fallbackIdentifier", 3, OMEKA_PATH . '/logs/identifier-debug.log');
        return $fallbackIdentifier;
        
    } catch (\Exception $e) {
        error_log("Error looking up item $itemId: " . $e->getMessage(), 3, OMEKA_PATH . '/logs/identifier-debug.log');
        // Return a safe fallback
        return "ITEM-$itemId";
    }
}

/**
 * ENHANCED: Improved findItemByIdentifier with better pattern matching
 */
private function findItemByIdentifier($identifier, $itemSetId = null) {
    try {
        error_log("=== ENHANCED SEARCH WITH PATTERN MATCHING ===", 3, OMEKA_PATH . '/logs/resource-links.log');
        error_log("Searching for identifier: '$identifier'" . ($itemSetId ? " constrained to item set: $itemSetId" : " (no constraint)"), 3, OMEKA_PATH . '/logs/resource-links.log');
        
        // Create variations of the identifier to search for
        $searchVariations = $this->generateIdentifierVariations($identifier);
        error_log("Generated search variations: " . implode(', ', $searchVariations), 3, OMEKA_PATH . '/logs/resource-links.log');
        
        foreach ($searchVariations as $searchTerm) {
            error_log("Trying search variation: '$searchTerm'", 3, OMEKA_PATH . '/logs/resource-links.log');
            
            // Strategy 1: Direct identifier search
            $searchParams = [
                'property' => [
                    [
                        'property' => 10, // dcterms:identifier property ID
                        'type' => 'eq',
                        'text' => $searchTerm
                    ]
                ],
                'limit' => 10
            ];
            
            if ($itemSetId) {
                $searchParams['item_set_id'] = $itemSetId;
            }
            
            $response = $this->api()->search('items', $searchParams);
            $items = $response->getContent();
            
            if (!empty($items)) {
                // Verify item set membership if constraint is provided
                foreach ($items as $item) {
                    if ($itemSetId) {
                        $belongsToItemSet = false;
                        foreach ($item->itemSets() as $itemSet) {
                            if ($itemSet->id() == $itemSetId) {
                                $belongsToItemSet = true;
                                break;
                            }
                        }
                        
                        if ($belongsToItemSet) {
                            error_log("✓ Found item with variation '$searchTerm' in correct item set: ID " . $item->id(), 3, OMEKA_PATH . '/logs/resource-links.log');
                            return $item;
                        }
                    } else {
                        error_log("✓ Found item with variation '$searchTerm': ID " . $item->id(), 3, OMEKA_PATH . '/logs/resource-links.log');
                        return $items[0];
                    }
                }
            }
            
            // Strategy 2: Title search for this variation
            $titleSearchParams = [
                'property' => [
                    [
                        'property' => 1, // dcterms:title property ID
                        'type' => 'in',
                        'text' => $searchTerm
                    ]
                ],
                'limit' => 10
            ];
            
            if ($itemSetId) {
                $titleSearchParams['item_set_id'] = $itemSetId;
            }
            
            $response = $this->api()->search('items', $titleSearchParams);
            $items = $response->getContent();
            
            if (!empty($items)) {
                foreach ($items as $item) {
                    if ($itemSetId) {
                        foreach ($item->itemSets() as $itemSet) {
                            if ($itemSet->id() == $itemSetId) {
                                error_log("✓ Found item by title containing '$searchTerm' in correct item set: ID " . $item->id(), 3, OMEKA_PATH . '/logs/resource-links.log');
                                return $item;
                            }
                        }
                    } else {
                        error_log("✓ Found item by title containing '$searchTerm': ID " . $item->id(), 3, OMEKA_PATH . '/logs/resource-links.log');
                        return $items[0];
                    }
                }
            }
        }
        
        error_log("❌ No item found with identifier '$identifier' (tried variations: " . implode(', ', $searchVariations) . ")" . ($itemSetId ? " in item set $itemSetId" : ""), 3, OMEKA_PATH . '/logs/resource-links.log');
        return null;
        
    } catch (\Exception $e) {
        error_log('Error finding item by identifier: ' . $e->getMessage(), 3, OMEKA_PATH . '/logs/resource-links.log');
        return null;
    }
}

/**
 * NEW: Generate variations of an identifier to improve matching
 */
private function generateIdentifierVariations($identifier) {
    $variations = [$identifier]; // Always include the original
    
    // Remove common prefixes and add as variations
    $prefixesToTry = ['Context-', 'SVU-', 'Square-', 'EXC-', 'CV-'];
    foreach ($prefixesToTry as $prefix) {
        if (strpos($identifier, $prefix) === 0) {
            $withoutPrefix = substr($identifier, strlen($prefix));
            $variations[] = $withoutPrefix;
            
            // For contexts, also try CV- prefix variations
            if ($prefix === 'Context-') {
                $variations[] = 'CV-' . $withoutPrefix;
            }
        }
    }
    
    // For numeric identifiers, try common prefixes
    if (preg_match('/^\d+$/', $identifier)) {
        $variations[] = 'CV-' . str_pad($identifier, 3, '0', STR_PAD_LEFT); // CV-001
        $variations[] = 'CV-001-' . $identifier; // CV-001-1
        
        // For square patterns
        if ($identifier <= 26) {
            $letter = chr(64 + ($identifier % 26 + 1)); // A, B, C...
            $number = ceil($identifier / 26);
            $variations[] = $letter . $number; // A1, A2, B1, etc.
        }
    }
    
    // Remove duplicates and return
    return array_unique($variations);
}



/**
 * Transform data from collecting form format to excavation format - UPDATED FOR NEW FORM
 */
private function transformCollectingFormToExcavationData($formData)
{
    error_log('RAW EXCAVATION COLLECTING FORM DATA: ' . print_r($formData, true), 3, OMEKA_PATH . '/logs/excavation-transform.log');

    $excavationData = [];
    
    // Updated field mappings based on new form structure
    $fieldMappings = [
        'prompt_32' => 'excavation_id',        // Acronym (excavation identifier)
        'prompt_35' => 'site_name',            // Name of the Location 
        'prompt_34' => 'parish',               // Parish of Excavation
        'prompt_97' => 'district',             // District of Excavation
        'prompt_51' => 'country',              // Country of Excavation
        'prompt_39' => 'latitude',             // GPS Latitude
        'prompt_40' => 'longitude',            // GPS Longitude
    ];
    
    // Process the basic form mappings
    foreach ($fieldMappings as $collectingField => $excavationField) {
        if (isset($formData[$collectingField]) && !empty($formData[$collectingField])) {
            $excavationData[$excavationField] = $formData[$collectingField];
        }
    }
    
    // Process archaeologist data
    $excavationData['archaeologist'] = $this->processArchaeologistDataFromForm($formData);
    
    // Process entities data (from the JavaScript enhanced form)
    if (isset($formData['entities_data']) && !empty($formData['entities_data'])) {
        $entitiesJson = $formData['entities_data'];
        error_log('Entities JSON: ' . $entitiesJson, 3, OMEKA_PATH . '/logs/excavation-transform.log');
        
        $entitiesData = json_decode($entitiesJson, true);
        if ($entitiesData) {
            $excavationData['entities'] = $entitiesData;
        }
    }
    
    // Ensure we have at least a default context if none provided
    if (empty($excavationData['entities']['contexts'])) {
        $excavationData['entities']['contexts'] = [
            [
                'context_id' => 'CTX-001',
                'context_description' => 'Default archaeological context',
                'context_type' => 'layer'
            ]
        ];
    }
    
    error_log('TRANSFORMED EXCAVATION DATA: ' . print_r($excavationData, true), 3, OMEKA_PATH . '/logs/excavation-transform.log');
    
    return $excavationData;
}


/**
 * Generate location TTL with proper structure - UPDATED WITH CLASS DECLARATIONS
 */
private function generateLocationTtl($locationUri, $gpsUri, $excavationData)
{
    // Initialize the TTL string for the main location entity
    $ttl = "<$locationUri> a excav:Location ;\n";
    
    // Use site_name as the informationName (Name of the Location from form)
    if (!empty($excavationData['site_name'])) {
        $ttl .= "    dbo:informationName \"" . $excavationData['site_name'] . "\"^^xsd:literal ;\n";
    }
    
    // References to entities (to be created after the main location)
    $entityDeclarations = "";
    
    // Add Country as DBpedia resource with class declaration
    if (!empty($excavationData['country'])) {
        $countrySlug = str_replace(' ', '_', $excavationData['country']);
        $countryUri = "http://dbpedia.org/resource/" . $countrySlug;
        $ttl .= "    dbo:Country <$countryUri> ;\n";
        
        // Add country declaration
        $entityDeclarations .= "<$countryUri> a dbo:Country ;\n";
        $entityDeclarations .= "    rdfs:label \"" . $excavationData['country'] . "\"^^xsd:literal ;\n";
        $entityDeclarations .= "    .\n\n";
    }
    
    // Add District as DBpedia resource with class declaration
    if (!empty($excavationData['district'])) {
        $districtSlug = str_replace(' ', '_', $excavationData['district']);
        $districtUri = "http://dbpedia.org/resource/" . $districtSlug;
        $ttl .= "    dbo:District <$districtUri> ;\n";
        
        // Add district declaration
        $entityDeclarations .= "<$districtUri> a dbo:District ;\n";
        $entityDeclarations .= "    rdfs:label \"" . $excavationData['district'] . "\"^^xsd:literal ;\n";
        $entityDeclarations .= "    .\n\n";
    }
    
    // Add Parish as DBpedia resource with class declaration
    if (!empty($excavationData['parish'])) {
        $parishSlug = str_replace(' ', '_', $excavationData['parish']);
        $parishUri = "http://dbpedia.org/resource/" . $parishSlug;
        $ttl .= "    dbo:Parish <$parishUri> ;\n";
        
        // Add parish declaration
        $entityDeclarations .= "<$parishUri> a dbo:Parish ;\n";
        $entityDeclarations .= "    rdfs:label \"" . $excavationData['parish'] . "\"^^xsd:literal ;\n";
        $entityDeclarations .= "    .\n\n";
    }
    
    // Add GPS coordinates if available
    if (!empty($excavationData['latitude']) || !empty($excavationData['longitude'])) {
        $ttl .= "    excav:hasGPSCoordinates <$gpsUri> ;\n";
    }
    
    // Close the location entity
    $ttl .= "    .\n\n";
    
    // Add the entity declarations
    $ttl .= $entityDeclarations;
    
    // Add GPS coordinates resource if available
    if (!empty($excavationData['latitude']) || !empty($excavationData['longitude'])) {
        $ttl .= "<$gpsUri> a excav:GPSCoordinates ;\n";
        
        if (!empty($excavationData['latitude'])) {
            $ttl .= "    geo:lat \"" . $excavationData['latitude'] . "\"^^xsd:decimal ;\n";
        }
        
        if (!empty($excavationData['longitude'])) {
            $ttl .= "    geo:long \"" . $excavationData['longitude'] . "\"^^xsd:decimal ;\n";
        }
        
        $ttl .= "    .\n\n";
    }
    
    return $ttl;
}

/**
 * Enhanced processExcavationData method with correct URI patterns
 */
private function processExcavationData($rdfData, $subject, &$itemData) {
    error_log('=== PROCESSING EXCAVATION DATA ===', 3, OMEKA_PATH . '/logs/excavation-processing.log');
    error_log('Processing excavation data for subject: ' . $subject, 3, OMEKA_PATH . '/logs/excavation-processing.log');
    
    // Get the current item set context to build correct URIs
    $currentItemSetId = $this->getCurrentItemSetContext();


    
    
    // Extract location information
    if (isset($rdfData[$subject]['http://www.ontologydesignpatterns.org/ont/dul/DUL.owl#hasLocation'])) {
        foreach ($rdfData[$subject]['http://www.ontologydesignpatterns.org/ont/dul/DUL.owl#hasLocation'] as $locObj) {
            if ($locObj['type'] === 'uri' && isset($rdfData[$locObj['value']])) {
                $locationUri = $locObj['value'];
                error_log('Processing location URI: ' . $locationUri, 3, OMEKA_PATH . '/logs/excavation-processing.log');
                
                // Extract location name (dbo:informationName)
                if (isset($rdfData[$locationUri]['http://dbpedia.org/ontology/informationName'])) {
                    foreach ($rdfData[$locationUri]['http://dbpedia.org/ontology/informationName'] as $nameObj) {
                        if ($nameObj['type'] === 'literal') {
                            $locationName = $nameObj['value'];
                            
                            if (!isset($itemData['Location Name'])) {
                                $itemData['Location Name'] = [];
                            }
                            
                            $itemData['Location Name'][] = [
                                'type' => 'literal',
                                'property_id' => 1811, 
                                '@value' => $locationName
                            ];
                            
                            error_log("Added location name: $locationName", 3, OMEKA_PATH . '/logs/excavation-processing.log');
                        }
                    }
                }
                
                // IMPORTANT: GPS coordinates are directly on the location object, not in a separate GPS object
                // Check for GPS coordinates directly on the location
                $lat = null;
                $long = null;
                
                if (isset($rdfData[$locationUri]['http://www.w3.org/2003/01/geo/wgs84_pos#lat'])) {
                    foreach ($rdfData[$locationUri]['http://www.w3.org/2003/01/geo/wgs84_pos#lat'] as $latObj) {
                        if ($latObj['type'] === 'literal') {
                            $lat = $latObj['value'];
                            error_log("Found latitude directly on location: $lat", 3, OMEKA_PATH . '/logs/excavation-processing.log');
                        }
                    }
                }
                
                if (isset($rdfData[$locationUri]['http://www.w3.org/2003/01/geo/wgs84_pos#long'])) {
                    foreach ($rdfData[$locationUri]['http://www.w3.org/2003/01/geo/wgs84_pos#long'] as $longObj) {
                        if ($longObj['type'] === 'literal') {
                            $long = $longObj['value'];
                            error_log("Found longitude directly on location: $long", 3, OMEKA_PATH . '/logs/excavation-processing.log');
                        }
                    }
                }
                
                // Add individual GPS coordinates
                if ($lat !== null) {
                    if (!isset($itemData['GPS Latitude'])) {
                        $itemData['GPS Latitude'] = [];
                    }
                    $itemData['GPS Latitude'][] = [
                        'type' => 'literal',
                        'property_id' => 257, // GPS Latitude property ID
                        '@value' => $lat
                    ];
                }
                
                if ($long !== null) {
                    if (!isset($itemData['GPS Longitude'])) {
                        $itemData['GPS Longitude'] = [];
                    }
                    $itemData['GPS Longitude'][] = [
                        'type' => 'literal',
                        'property_id' => 259, // GPS Longitude property ID
                        '@value' => $long
                    ];
                }
                
                // Add combined GPS coordinates
                if ($lat !== null && $long !== null) {
                    if (!isset($itemData['GPS Coordinates'])) {
                        $itemData['GPS Coordinates'] = [];
                    }
                    $itemData['GPS Coordinates'][] = [
                        'type' => 'literal',
                        'property_id' => 7664, // GPS Coordinates property ID
                        '@value' => "Latitude: $lat, Longitude: $long"
                    ];
                    
                    error_log("Added GPS coordinates: Lat=$lat, Long=$long", 3, OMEKA_PATH . '/logs/excavation-processing.log');
                }
                
                // FIXED: Process location properties with CORRECT lowercase URIs
                $locationProperties = [
                    'http://dbpedia.org/ontology/district' => ['District', 1555],  // lowercase 'district'
                    'http://dbpedia.org/ontology/parish' => ['Parish', 1681],      // lowercase 'parish'
                    'http://dbpedia.org/ontology/Country' => ['Country', 1402]     // uppercase 'Country'
                ];
                
                foreach ($locationProperties as $propertyUri => $propertyInfo) {
                    if (isset($rdfData[$locationUri][$propertyUri])) {
                        $propertyLabel = $propertyInfo[0];
                        $propertyId = $propertyInfo[1];
                        
                        error_log("Processing location property: $propertyUri", 3, OMEKA_PATH . '/logs/excavation-processing.log');
                        
                        foreach ($rdfData[$locationUri][$propertyUri] as $propObj) {
                            if ($propObj['type'] === 'uri') {
                                // Extract name from URI - handle both DBpedia and normalized URIs
                                $parts = explode('/', $propObj['value']);
                                $value = str_replace('_', ' ', end($parts));
                                
                                // For normalized URIs, try to get a more readable name
                                if (isset($rdfData[$propObj['value']])) {
                                    // If it's a district/parish with a name property, use that
                                    $referencedEntity = $rdfData[$propObj['value']];
                                    // Check for various name properties
                                    $nameProperties = [
                                        'http://www.w3.org/2000/01/rdf-schema#label',
                                        'http://dbpedia.org/ontology/name',
                                        'http://purl.org/dc/terms/title'
                                    ];
                                    
                                    foreach ($nameProperties as $nameProp) {
                                        if (isset($referencedEntity[$nameProp])) {
                                            foreach ($referencedEntity[$nameProp] as $nameObj) {
                                                if ($nameObj['type'] === 'literal') {
                                                    $value = $nameObj['value'];
                                                    break 2;
                                                }
                                            }
                                        }
                                    }
                                }
                                
                                if (!isset($itemData[$propertyLabel])) {
                                    $itemData[$propertyLabel] = [];
                                }
                                
                                $itemData[$propertyLabel][] = [
                                    'type' => 'literal',
                                    'property_id' => $propertyId,
                                    '@value' => $value
                                ];
                                
                                error_log("Added $propertyLabel: $value", 3, OMEKA_PATH . '/logs/excavation-processing.log');
                            }
                        }
                    } else {
                        error_log("Property $propertyUri not found in location $locationUri", 3, OMEKA_PATH . '/logs/excavation-processing.log');
                    }
                }
            }
        }
    }
    
    // FIXED: Process archaeologist with CORRECT normalized URIs
    // Try both original and normalized property URIs
    $archaeologistPropertyUris = [
        'https://purl.org/megalod/ms/excavation/hasPersonInCharge'
    ];
    


// In the processExcavationData method, modify the code that checks for GPS coordinates via hasGPSCoordinates:

// Check for GPS coordinates via hasGPSCoordinates reference
if (isset($rdfData[$locationUri]['https://purl.org/megalod/ms/excavation/hasGPSCoordinates']) ||
    isset($rdfData[$locationUri]["https://purl.org/megalod/$currentItemSetId/excavation/hasGPSCoordinates"])) {
    
// Add this variation to the $gpsPropertyUris array
$gpsPropertyUris = [
    'https://purl.org/megalod/ms/excavation/hasGPSCoordinates',
    "https://purl.org/megalod/$currentItemSetId/excavation/hasGPSCoordinates",
    'excav:hasGPSCoordinates'  // Add this line to check for compact URI format
];
    
    foreach ($gpsPropertyUris as $gpsPropertyUri) {
        if (isset($rdfData[$locationUri][$gpsPropertyUri])) {
            foreach ($rdfData[$locationUri][$gpsPropertyUri] as $gpsObj) {
                if ($gpsObj['type'] === 'uri' && isset($rdfData[$gpsObj['value']])) {
                    $gpsUri = $gpsObj['value'];
                    
                    // Extract lat/long from the GPS object
                    if (isset($rdfData[$gpsUri]['http://www.w3.org/2003/01/geo/wgs84_pos#lat'])) {
                        foreach ($rdfData[$gpsUri]['http://www.w3.org/2003/01/geo/wgs84_pos#lat'] as $latObj) {
                            if ($latObj['type'] === 'literal') {
                                $lat = $latObj['value'];
                                error_log("Found GPS latitude from referenced object: $lat", 3, OMEKA_PATH . '/logs/gps-debug.log');
                                
                                // Add to itemData
                                if (!isset($itemData['GPS Latitude'])) {
                                    $itemData['GPS Latitude'] = [];
                                }
                                $itemData['GPS Latitude'][] = [
                                    'type' => 'literal',
                                    'property_id' => 257, // GPS Latitude property ID
                                    '@value' => $lat
                                ];
                            }
                        }
                    }
                    
                    if (isset($rdfData[$gpsUri]['http://www.w3.org/2003/01/geo/wgs84_pos#long'])) {
                        foreach ($rdfData[$gpsUri]['http://www.w3.org/2003/01/geo/wgs84_pos#long'] as $longObj) {
                            if ($longObj['type'] === 'literal') {
                                $long = $longObj['value'];
                                error_log("Found GPS longitude from referenced object: $long", 3, OMEKA_PATH . '/logs/gps-debug.log');
                                
                                // Add to itemData
                                if (!isset($itemData['GPS Longitude'])) {
                                    $itemData['GPS Longitude'] = [];
                                }
                                $itemData['GPS Longitude'][] = [
                                    'type' => 'literal',
                                    'property_id' => 259, // GPS Longitude property ID
                                    '@value' => $long
                                ];
                            }
                        }
                    }
                    
                    // If we have both lat and long, add the combined coordinates
                    if (isset($lat) && isset($long)) {
                        if (!isset($itemData['GPS Coordinates'])) {
                            $itemData['GPS Coordinates'] = [];
                        }
                        $itemData['GPS Coordinates'][] = [
                            'type' => 'literal',
                            'property_id' => 7664, // GPS Coordinates property ID
                            '@value' => "Latitude: $lat, Longitude: $long"
                        ];
                        
                        error_log("Added GPS coordinates from reference: Lat=$lat, Long=$long", 3, OMEKA_PATH . '/logs/gps-debug.log');
                    }
                }
            }
            break;
        }
    }
}
    
    // Add normalized URI if we have the item set context
    if ($currentItemSetId) {
        $archaeologistPropertyUris[] = "https://purl.org/megalod/$currentItemSetId/excavation/hasPersonInCharge";
    }
    
    foreach ($archaeologistPropertyUris as $archaeologistPropertyUri) {
        if (isset($rdfData[$subject][$archaeologistPropertyUri])) {
            error_log("Found archaeologist property: $archaeologistPropertyUri", 3, OMEKA_PATH . '/logs/excavation-processing.log');
            
            foreach ($rdfData[$subject][$archaeologistPropertyUri] as $archaeologistObj) {
                if ($archaeologistObj['type'] === 'uri' && isset($rdfData[$archaeologistObj['value']])) {
                    $archaeologistUri = $archaeologistObj['value'];
                    error_log('Processing archaeologist URI: ' . $archaeologistUri, 3, OMEKA_PATH . '/logs/excavation-processing.log');
                    
                    // Extract archaeologist data
                    $archaeologistData = $this->extractArchaeologistData($rdfData, $archaeologistUri);
                    
                    if ($archaeologistData) {
                        // Add archaeologist name
                        if ($archaeologistData['name']) {
                            if (!isset($itemData['Archaeologist Name'])) {
                                $itemData['Archaeologist Name'] = [];
                            }
                            
                            $itemData['Archaeologist Name'][] = [
                                'type' => 'literal',
                                'property_id' => 7665, // Person in charge property ID
                                '@value' => $archaeologistData['name']
                            ];
                            
                            error_log("Added archaeologist name: " . $archaeologistData['name'], 3, OMEKA_PATH . '/logs/excavation-processing.log');
                        }
                        
                        // Add ORCID if available
                        if ($archaeologistData['orcid']) {
                            if (!isset($itemData['Archaeologist ORCID'])) {
                                $itemData['Archaeologist ORCID'] = [];
                            }
                            
                            $itemData['Archaeologist ORCID'][] = [
                                'type' => 'literal',
                                'property_id' => 176, // Generic property ID
                                '@value' => $archaeologistData['orcid']
                            ];
                            
                            error_log("Added archaeologist ORCID: " . $archaeologistData['orcid'], 3, OMEKA_PATH . '/logs/excavation-processing.log');
                        }
                        
                        // Add email if available
                        if ($archaeologistData['email']) {
                            if (!isset($itemData['Archaeologist Email'])) {
                                $itemData['Archaeologist Email'] = [];
                            }
                            
                            $itemData['Archaeologist Email'][] = [
                                'type' => 'literal',
                                'property_id' => 123, // Generic property ID
                                '@value' => $archaeologistData['email']
                            ];
                            
                            error_log("Added archaeologist email: " . $archaeologistData['email'], 3, OMEKA_PATH . '/logs/excavation-processing.log');
                        }
                        
                        // Keep the original combined field for backward compatibility
                        if (!isset($itemData['Person in Charge'])) {
                            $itemData['Person in Charge'] = [];
                        }
                        
                        $personInfo = $archaeologistData['name'] ?: $archaeologistData['orcid'];
                        if ($archaeologistData['name'] && $archaeologistData['orcid']) {
                            $personInfo = $archaeologistData['name'] . ' (ORCID: ' . $archaeologistData['orcid'] . ')';
                        }
                        
                        $itemData['Person in Charge'][] = [
                            'type' => 'literal',
                            'property_id' => 7665,
                            '@value' => $personInfo
                        ];
                    }
                }
            }
            break; // Found the property, no need to check others
        }
    }
    
    // FIXED: Process contexts with CORRECT normalized URIs
    $contextList = [];
    $contextPropertyUris = [
        'https://purl.org/megalod/ms/excavation/hasContext'
    ];
    
    if ($currentItemSetId) {
        $contextPropertyUris[] = "https://purl.org/megalod/$currentItemSetId/excavation/hasContext";
    }
    
    foreach ($contextPropertyUris as $contextPropertyUri) {
        if (isset($rdfData[$subject][$contextPropertyUri])) {
            error_log("Found context property: $contextPropertyUri", 3, OMEKA_PATH . '/logs/excavation-processing.log');
            
            foreach ($rdfData[$subject][$contextPropertyUri] as $contextObj) {
                if ($contextObj['type'] === 'uri') {
                    $contextId = $this->extractResourceIdentifier($rdfData, $contextObj['value']);
                    if ($contextId) {
                        $contextList[] = $contextId;
                        
                        // Try to get context description if available
                        if (isset($rdfData[$contextObj['value']])) {
                            $contextDesc = $this->extractContextDescription($rdfData, $contextObj['value']);
                            if ($contextDesc) {
                                $contextList[count($contextList) - 1] = "$contextId: $contextDesc";
                            }
                        }
                    }
                }
            }
            break;
        }
    }
    
    if (!empty($contextList)) {
        if (!isset($itemData['Excavation Contexts'])) {
            $itemData['Excavation Contexts'] = [];
        }
        
        $itemData['Excavation Contexts'][] = [
            'type' => 'literal',
            'property_id' => 7666,
            '@value' => implode(' | ', $contextList)
        ];
        
        error_log("Added contexts: " . implode(', ', $contextList), 3, OMEKA_PATH . '/logs/excavation-processing.log');
    }

    // process svu data
    if (isset($rdfData[$subject]['https://purl.org/megalod/ms/excavation/hasSVU'])) {
        foreach ($rdfData[$subject]['https://purl.org/megalod/ms/excavation/hasSVU'] as $svuObj) {
            if ($svuObj['type'] === 'uri' && isset($rdfData[$svuObj['value']])) {
                $svuUri = $svuObj['value'];
                error_log('Processing SVU URI: ' . $svuUri, 3, OMEKA_PATH . '/logs/excavation-processing.log');
                
                // Extract SVU data
                $svuData = $this->extractSvuData($rdfData, $svuUri);
                
                if ($svuData) {
                    // Add SVU name
                    if ($svuData['name']) {
                        if (!isset($itemData['SVU Name'])) {
                            $itemData['SVU Name'] = [];
                        }
                        
                        $itemData['SVU Name'][] = [
                            'type' => 'literal',
                            'property_id' => 7667, // SVU Name property ID
                            '@value' => $svuData['name']
                        ];
                        
                        error_log("Added SVU name: " . $svuData['name'], 3, OMEKA_PATH . '/logs/excavation-processing.log');
                    }
                    
                    // Add SVU description
                    if ($svuData['description']) {
                        if (!isset($itemData['SVU Description'])) {
                            $itemData['SVU Description'] = [];
                        }
                        
                        $itemData['SVU Description'][] = [
                            'type' => 'literal',
                            'property_id' => 7669, // SVU Description property ID
                            '@value' => $svuData['description']
                        ];
                        
                        error_log("Added SVU description: " . $svuData['description'], 3, OMEKA_PATH . '/logs/excavation-processing.log');
                    }
                }
            }
        }
    }
    
    // FIXED: Process squares with CORRECT normalized URIs
    $squareList = [];
    $squarePropertyUris = [
        'https://purl.org/megalod/ms/excavation/hasSquare'
    ];
    
    if ($currentItemSetId) {
        $squarePropertyUris[] = "https://purl.org/megalod/$currentItemSetId/excavation/hasSquare";
    }
    
    foreach ($squarePropertyUris as $squarePropertyUri) {
        if (isset($rdfData[$subject][$squarePropertyUri])) {
            error_log("Found square property: $squarePropertyUri", 3, OMEKA_PATH . '/logs/excavation-processing.log');
            
            foreach ($rdfData[$subject][$squarePropertyUri] as $squareObj) {
                if ($squareObj['type'] === 'uri') {
                    $squareId = $this->extractResourceIdentifier($rdfData, $squareObj['value']);
                    if ($squareId) {
                        $squareList[] = $squareId;
                        
                        // Try to get square coordinates if available
                        if (isset($rdfData[$squareObj['value']])) {
                            $squareCoords = $this->extractSquareCoordinates($rdfData, $squareObj['value']);
                            if ($squareCoords) {
                                $squareList[count($squareList) - 1] = "$squareId ($squareCoords)";
                            }
                        }
                    }
                }
            }
            break;
        }
    }
    
    if (!empty($squareList)) {
        if (!isset($itemData['Excavation Squares'])) {
            $itemData['Excavation Squares'] = [];
        }
        
        $itemData['Excavation Squares'][] = [
            'type' => 'literal',
            'property_id' => 7668,
            '@value' => implode(' | ', $squareList)
        ];
        
        error_log("Added squares: " . implode(', ', $squareList), 3, OMEKA_PATH . '/logs/excavation-processing.log');
    }
    
    error_log('Finished processing excavation data', 3, OMEKA_PATH . '/logs/excavation-processing.log');
}

/**
 * Helper method to extract context description
 */
private function extractContextDescription($rdfData, $contextUri) {
    if (isset($rdfData[$contextUri]['http://purl.org/dc/terms/description'])) {
        foreach ($rdfData[$contextUri]['http://purl.org/dc/terms/description'] as $descObj) {
            if ($descObj['type'] === 'literal') {
                return $descObj['value'];
            }
        }
    }
    return null;
}

/**
 * Helper method to extract square coordinates
 */
private function extractSquareCoordinates($rdfData, $squareUri) {
    $coords = [];
    
    if (isset($rdfData[$squareUri]['http://www.w3.org/2003/01/geo/wgs84_pos#lat'])) {
        foreach ($rdfData[$squareUri]['http://www.w3.org/2003/01/geo/wgs84_pos#lat'] as $latObj) {
            if ($latObj['type'] === 'literal') {
                $coords[] = 'Lat: ' . $latObj['value'];
            }
        }
    }
    
    if (isset($rdfData[$squareUri]['http://www.w3.org/2003/01/geo/wgs84_pos#long'])) {
        foreach ($rdfData[$squareUri]['http://www.w3.org/2003/01/geo/wgs84_pos#long'] as $longObj) {
            if ($longObj['type'] === 'literal') {
                $coords[] = 'Long: ' . $longObj['value'];
            }
        }
    }
    
    return !empty($coords) ? implode(', ', $coords) : null;
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
    // Get the informationName property
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
 * Fixed processSVUData method to properly extract description and timeline
 */
private function processSVUData($rdfData, $subject, &$itemData) {
    error_log('=== PROCESSING SVU DATA ===', 3, OMEKA_PATH . '/logs/svu-processing.log');
    error_log('Processing SVU data for subject: ' . $subject, 3, OMEKA_PATH . '/logs/svu-processing.log');
    
    // Get the current item set context to build correct URIs
    $currentItemSetId = $this->getCurrentItemSetContext();
    
    // Basic properties - FIXED: direct mapping for literal values
    $propertyMap = [
        'http://purl.org/dc/terms/identifier' => ['SVU ID', 10],
        'http://purl.org/dc/terms/description' => ['Description', 4],
    ];
    
    // Extract basic properties correctly
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
                    
                    error_log("Added $term: " . $object['value'], 3, OMEKA_PATH . '/logs/svu-processing.log');
                }
            }
        } else {
            error_log("Property $predicate not found for subject $subject", 3, OMEKA_PATH . '/logs/svu-processing.log');
        }
    }
    
    // FIXED: Extract timeline details with both original and normalized URIs
    $timelinePropertyUris = [
        'https://purl.org/megalod/ms/excavation/hasTimeline'
    ];
    
    // Add normalized URI if we have the item set context
    if ($currentItemSetId) {
        $timelinePropertyUris[] = "https://purl.org/megalod/$currentItemSetId/excavation/hasTimeline";
    }
    
    foreach ($timelinePropertyUris as $timelinePropertyUri) {
        if (isset($rdfData[$subject][$timelinePropertyUri])) {
            error_log("Found timeline property: $timelinePropertyUri", 3, OMEKA_PATH . '/logs/svu-processing.log');
            
            foreach ($rdfData[$subject][$timelinePropertyUri] as $timelineObj) {
                if ($timelineObj['type'] === 'uri' && isset($rdfData[$timelineObj['value']])) {
                    $timelineUri = $timelineObj['value'];
                    error_log("Processing timeline URI: $timelineUri", 3, OMEKA_PATH . '/logs/svu-processing.log');
                    
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
                                error_log("Processing beginning URI: $beginUri", 3, OMEKA_PATH . '/logs/svu-processing.log');
                                
                                // Extract year
                                if (isset($rdfData[$beginUri]['http://www.w3.org/2006/time#inXSDgYear'])) {
                                    foreach ($rdfData[$beginUri]['http://www.w3.org/2006/time#inXSDgYear'] as $yearObj) {
                                        if ($yearObj['type'] === 'literal') {
                                            $beginningYear = abs((int)$yearObj['value']); // Remove negative sign for BC dates
                                            error_log("Found beginning year: $beginningYear", 3, OMEKA_PATH . '/logs/svu-processing.log');
                                        }
                                    }
                                }
                                
                                // Extract BC/AD with both original and normalized URIs
                                $bcadPropertyUris = [
                                    'https://purl.org/megalod/ms/excavation/bcad'
                                ];
                                if ($currentItemSetId) {
                                    $bcadPropertyUris[] = "https://purl.org/megalod/$currentItemSetId/excavation/bcad";
                                }
                                
                                foreach ($bcadPropertyUris as $bcadPropertyUri) {
                                    if (isset($rdfData[$beginUri][$bcadPropertyUri])) {
                                        foreach ($rdfData[$beginUri][$bcadPropertyUri] as $bcObj) {
                                            if ($bcObj['type'] === 'uri') {
                                                $parts = explode('/', $bcObj['value']);
                                                $bcacValue = end($parts);
                                                $beginningBC = ($bcacValue === 'BC');
                                                error_log("Found beginning BC/AD: $bcacValue", 3, OMEKA_PATH . '/logs/svu-processing.log');
                                                break 2;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                    
                    // Extract end (similar process)
                    if (isset($rdfData[$timelineUri]['http://www.w3.org/2006/time#hasEnd'])) {
                        foreach ($rdfData[$timelineUri]['http://www.w3.org/2006/time#hasEnd'] as $endObj) {
                            if ($endObj['type'] === 'uri' && isset($rdfData[$endObj['value']])) {
                                $endUri = $endObj['value'];
                                error_log("Processing end URI: $endUri", 3, OMEKA_PATH . '/logs/svu-processing.log');
                                
                                // Extract year
                                if (isset($rdfData[$endUri]['http://www.w3.org/2006/time#inXSDgYear'])) {
                                    foreach ($rdfData[$endUri]['http://www.w3.org/2006/time#inXSDgYear'] as $yearObj) {
                                        if ($yearObj['type'] === 'literal') {
                                            $endYear = abs((int)$yearObj['value']); // Remove negative sign for BC dates
                                            error_log("Found end year: $endYear", 3, OMEKA_PATH . '/logs/svu-processing.log');
                                        }
                                    }
                                }
                                
                                // Extract BC/AD
                                $bcadPropertyUris = [
                                    'https://purl.org/megalod/ms/excavation/bcad'
                                ];
                                if ($currentItemSetId) {
                                    $bcadPropertyUris[] = "https://purl.org/megalod/$currentItemSetId/excavation/bcad";
                                }
                                
                                foreach ($bcadPropertyUris as $bcadPropertyUri) {
                                    if (isset($rdfData[$endUri][$bcadPropertyUri])) {
                                        foreach ($rdfData[$endUri][$bcadPropertyUri] as $bcObj) {
                                            if ($bcObj['type'] === 'uri') {
                                                $parts = explode('/', $bcObj['value']);
                                                $bcacValue = end($parts);
                                                $endBC = ($bcacValue === 'BC');
                                                error_log("Found end BC/AD: $bcacValue", 3, OMEKA_PATH . '/logs/svu-processing.log');
                                                break 2;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                    
                    
                    
                    // Add combined timeline range
                    if ($beginningYear && $endYear) {
                        if (!isset($itemData['Chronological Period'])) {
                            $itemData['Chronological Period'] = [];
                        }
                        
                        $beginText = $beginningYear . ($beginningBC ? ' BC' : ' AD');
                        $endText = $endYear . ($endBC ? ' BC' : ' AD');
                        $timelineRange = "$beginText - $endText";
                        
                        $itemData['Chronological Period'][] = [
                            'type' => 'literal',
                            'property_id' => 7669, // Timeline property ID
                            '@value' => $timelineRange
                        ];
                        
                        error_log("Added chronological period: $timelineRange", 3, OMEKA_PATH . '/logs/svu-processing.log');
                    }
                }
            }
            break; // Found the timeline property, no need to check others
        } else {
            error_log("Timeline property $timelinePropertyUri not found", 3, OMEKA_PATH . '/logs/svu-processing.log');
        }
    }
    
    // Debug: Log all available properties for this SVU
    if (isset($rdfData[$subject])) {
        error_log("Available properties for SVU $subject: " . implode(', ', array_keys($rdfData[$subject])), 3, OMEKA_PATH . '/logs/svu-processing.log');
    }
    
    error_log('Finished processing SVU data', 3, OMEKA_PATH . '/logs/svu-processing.log');
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
 * Enhanced processContextData method to properly show linked SVUs
 */
private function processContextData($rdfData, $subject, &$itemData) {
    error_log('=== PROCESSING CONTEXT DATA ===', 3, OMEKA_PATH . '/logs/context-processing.log');
    error_log('Processing context data for subject: ' . $subject, 3, OMEKA_PATH . '/logs/context-processing.log');
    
    // Get the current item set context to build correct URIs
    $currentItemSetId = $this->getCurrentItemSetContext();
    
    // Basic properties - direct mapping
    $propertyMap = [
        'http://purl.org/dc/terms/identifier' => ['Context ID', 10],
        'http://purl.org/dc/terms/description' => ['Context Description', 4],
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
                    
                    error_log("Added $term: " . $object['value'], 3, OMEKA_PATH . '/logs/context-processing.log');
                }
            }
        }
    }
    
    // ENHANCED: Process SVU relationships with both original and normalized URIs
    $svuPropertyUris = [
        'https://purl.org/megalod/ms/excavation/hasSVU'
    ];
    
    // Add normalized URI if we have the item set context
    if ($currentItemSetId) {
        $svuPropertyUris[] = "https://purl.org/megalod/$currentItemSetId/excavation/hasSVU";
    }
    
    $linkedSVUs = [];
    $svuDetails = [];
    
    foreach ($svuPropertyUris as $svuPropertyUri) {
        if (isset($rdfData[$subject][$svuPropertyUri])) {
            error_log("Found SVU property: $svuPropertyUri", 3, OMEKA_PATH . '/logs/context-processing.log');
            
            foreach ($rdfData[$subject][$svuPropertyUri] as $svuObj) {
                if ($svuObj['type'] === 'uri') {
                    $svuUri = $svuObj['value'];
                    $svuId = $this->extractResourceIdentifier($rdfData, $svuUri);
                    
                    if ($svuId) {
                        $linkedSVUs[] = $svuId;
                        
                        // Extract additional SVU details if available
                        if (isset($rdfData[$svuUri])) {
                            $svuDescription = null;
                            $svuTimeline = null;
                            
                            // Get SVU description
                            if (isset($rdfData[$svuUri]['http://purl.org/dc/terms/description'])) {
                                foreach ($rdfData[$svuUri]['http://purl.org/dc/terms/description'] as $descObj) {
                                    if ($descObj['type'] === 'literal') {
                                        $svuDescription = $descObj['value'];
                                        break;
                                    }
                                }
                            }
                            
                            // Get timeline information
                            $timelinePropertyUris = [
                                'https://purl.org/megalod/ms/excavation/hasTimeline'
                            ];
                            if ($currentItemSetId) {
                                $timelinePropertyUris[] = "https://purl.org/megalod/$currentItemSetId/excavation/hasTimeline";
                            }
                            
                            foreach ($timelinePropertyUris as $timelinePropertyUri) {
                                if (isset($rdfData[$svuUri][$timelinePropertyUri])) {
                                    foreach ($rdfData[$svuUri][$timelinePropertyUri] as $timelineObj) {
                                        if ($timelineObj['type'] === 'uri' && isset($rdfData[$timelineObj['value']])) {
                                            $timelineRange = $this->extractTimelineRange($rdfData, $timelineObj['value']);
                                            if ($timelineRange) {
                                                $svuTimeline = $timelineRange;
                                                break 2;
                                            }
                                        }
                                    }
                                }
                            }
                            
                            // Build detailed SVU info
                            $svuDetail = $svuId;
                            if ($svuDescription) {
                                $svuDetail .= ": $svuDescription";
                            }
                            if ($svuTimeline) {
                                $svuDetail .= " ($svuTimeline)";
                            }
                            
                            $svuDetails[] = $svuDetail;
                            
                            error_log("Processed SVU: $svuDetail", 3, OMEKA_PATH . '/logs/context-processing.log');
                        } else {
                            $svuDetails[] = $svuId;
                            error_log("Added basic SVU: $svuId", 3, OMEKA_PATH . '/logs/context-processing.log');
                        }
                    }
                }
            }
            break; // Found the property, no need to check others
        }
    }
    
    // Add SVU information to context
    if (!empty($linkedSVUs)) {
        // Simple list of SVU IDs
        if (!isset($itemData['Linked Stratigraphic Units'])) {
            $itemData['Linked Stratigraphic Units'] = [];
        }
        
        $itemData['Linked Stratigraphic Units'][] = [
            'type' => 'literal',
            'property_id' => 7667, // SVU property ID
            '@value' => implode(', ', $linkedSVUs)
        ];
        
        error_log("Added SVUs to context: " . implode(', ', $linkedSVUs), 3, OMEKA_PATH . '/logs/context-processing.log');
    } else {
        error_log("No SVUs found for context: $subject", 3, OMEKA_PATH . '/logs/context-processing.log');
        
        // Debug: Log available properties
        if (isset($rdfData[$subject])) {
            error_log("Available properties for context: " . implode(', ', array_keys($rdfData[$subject])), 3, OMEKA_PATH . '/logs/context-processing.log');
        }
    }
    
    error_log('Finished processing context data', 3, OMEKA_PATH . '/logs/context-processing.log');
}

/**
 * Enhanced extractTimelineRange method with better BC/AD handling
 */
private function extractTimelineRange($rdfData, $timelineUri) {
    if (!isset($rdfData[$timelineUri])) {
        return null;
    }
    
    error_log("Extracting timeline range from: $timelineUri", 3, OMEKA_PATH . '/logs/timeline-debug.log');
    
    $beginningYear = null;
    $beginningBC = null;
    $endYear = null;
    $endBC = null;
    
    // Get current item set context for normalized URIs
    $currentItemSetId = $this->getCurrentItemSetContext();
    
    // Extract beginning
    if (isset($rdfData[$timelineUri]['http://www.w3.org/2006/time#hasBeginning'])) {
        foreach ($rdfData[$timelineUri]['http://www.w3.org/2006/time#hasBeginning'] as $beginObj) {
            if ($beginObj['type'] === 'uri' && isset($rdfData[$beginObj['value']])) {
                $beginUri = $beginObj['value'];
                
                // Extract year
                if (isset($rdfData[$beginUri]['http://www.w3.org/2006/time#inXSDgYear'])) {
                    foreach ($rdfData[$beginUri]['http://www.w3.org/2006/time#inXSDgYear'] as $yearObj) {
                        if ($yearObj['type'] === 'literal') {
                            $beginningYear = abs((int)$yearObj['value']); // Remove negative sign
                        }
                    }
                }
                
                // Extract BC/AD with normalized URIs
                $bcadPropertyUris = [
                    'https://purl.org/megalod/ms/excavation/bcad'
                ];
                if ($currentItemSetId) {
                    $bcadPropertyUris[] = "https://purl.org/megalod/$currentItemSetId/excavation/bcad";
                }
                
                foreach ($bcadPropertyUris as $bcadPropertyUri) {
                    if (isset($rdfData[$beginUri][$bcadPropertyUri])) {
                        foreach ($rdfData[$beginUri][$bcadPropertyUri] as $bcObj) {
                            if ($bcObj['type'] === 'uri') {
                                $parts = explode('/', $bcObj['value']);
                                $bcacValue = end($parts);
                                $beginningBC = ($bcacValue === 'BC');
                                break 2;
                            }
                        }
                    }
                }
            }
        }
    }
    
    // Extract end (similar process)
    if (isset($rdfData[$timelineUri]['http://www.w3.org/2006/time#hasEnd'])) {
        foreach ($rdfData[$timelineUri]['http://www.w3.org/2006/time#hasEnd'] as $endObj) {
            if ($endObj['type'] === 'uri' && isset($rdfData[$endObj['value']])) {
                $endUri = $endObj['value'];
                
                // Extract year
                if (isset($rdfData[$endUri]['http://www.w3.org/2006/time#inXSDgYear'])) {
                    foreach ($rdfData[$endUri]['http://www.w3.org/2006/time#inXSDgYear'] as $yearObj) {
                        if ($yearObj['type'] === 'literal') {
                            $endYear = abs((int)$yearObj['value']); // Remove negative sign
                        }
                    }
                }
                
                // Extract BC/AD
                $bcadPropertyUris = [
                    'https://purl.org/megalod/ms/excavation/bcad'
                ];
                if ($currentItemSetId) {
                    $bcadPropertyUris[] = "https://purl.org/megalod/$currentItemSetId/excavation/bcad";
                }
                
                foreach ($bcadPropertyUris as $bcadPropertyUri) {
                    if (isset($rdfData[$endUri][$bcadPropertyUri])) {
                        foreach ($rdfData[$endUri][$bcadPropertyUri] as $bcObj) {
                            if ($bcObj['type'] === 'uri') {
                                $parts = explode('/', $bcObj['value']);
                                $bcacValue = end($parts);
                                $endBC = ($bcacValue === 'BC');
                                break 2;
                            }
                        }
                    }
                }
            }
        }
    }
    
    // Format timeline range
    if ($beginningYear && $endYear) {
        $beginText = $beginningYear . ($beginningBC ? ' BC' : ' AD');
        $endText = $endYear . ($endBC ? ' BC' : ' AD');
        return "$beginText - $endText";
    } else if ($beginningYear) {
        $beginText = $beginningYear . ($beginningBC ? ' BC' : ' AD');
        return "From $beginText";
    } else if ($endYear) {
        $endText = $endYear . ($endBC ? ' BC' : ' AD');
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
        'http://www.cidoc-crm.org/cidoc-crm/E55_Type' => ['Type', 399],
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
    $skippedItems = [];
    
    // First, check for duplicate identifiers within the current data
    $identifierMap = [];
    $duplicatesInBatch = [];
    
    foreach ($omekaData as $itemIndex => $itemData) {
        $identifier = $this->extractIdentifierFromItemData($itemData);
        if ($identifier) {
            if (isset($identifierMap[$identifier])) {
                $duplicatesInBatch[] = $identifier;
                $errors[] = "Duplicate identifier '$identifier' found in the current batch (items $identifierMap[$identifier] and $itemIndex)";
            } else {
                $identifierMap[$identifier] = $itemIndex;
            }
        }
    }
    
    // Process each item
    foreach ($omekaData as $itemIndex => $itemData) {
        $identifier = $this->extractIdentifierFromItemData($itemData);
        
        // Skip items with duplicate identifiers in the current batch
        if ($identifier && in_array($identifier, $duplicatesInBatch)) {
            $skippedItems[] = [
                'index' => $itemIndex,
                'identifier' => $identifier,
                'reason' => 'Duplicate identifier in current batch'
            ];
            continue;
        }
        
        // Check if item with this identifier already exists in the item set
        if ($identifier && $itemSetId && $this->itemExistsWithIdentifier($identifier, $itemSetId)) {
            $skippedItems[] = [
                'index' => $itemIndex,
                'identifier' => $identifier,
                'reason' => 'Item with this identifier already exists in the item set'
            ];
            $errors[] = "Skipped item $itemIndex: An item with identifier '$identifier' already exists in item set #$itemSetId";
            continue;
        }

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
            error_log('Omeka S Item Created Successfully: ID=' . $itemId . ($identifier ? ", Identifier=$identifier" : ""));
        }
    }

    if ($itemSetId && !empty($createdItems) && $this->excavationData) {
        // Update the item set with excavation info
        $this->updateItemSetWithExcavationInfo($itemSetId, $this->excavationData);
    }

    // Log skipped items for debugging
    if (!empty($skippedItems)) {
        error_log('Skipped items due to duplicate identifiers: ' . print_r($skippedItems, true), 3, OMEKA_PATH . '/logs/duplicate-identifiers.log');
    }

    return [
        'errors' => $errors,
        'created_items' => $createdItems,
        'skipped_items' => $skippedItems
    ];
}


private function itemExistsWithIdentifier($identifier, $itemSetId) {
    try {
        error_log("Checking for existing item with identifier '$identifier' in item set #$itemSetId", 3, OMEKA_PATH . '/logs/duplicate-identifiers.log');
        
        // ALWAYS ALLOW SQUARES, CONTEXTS, LOCATIONS TO BE DUPLICATED
        // These are referenced resources that should be allowed to exist multiple times
        if (in_array($identifier, ['A1', 'A2', 'A3', 'A4', 'B1', 'B2', 'B3', 'B4', 'C1', 'C2', 'C3', 'C4', 'D1', 'D2', 'D3', 'D4']) ||
            strpos($identifier, 'excavation-location') !== false ||
            strpos($identifier, 'CV-') === 0) {
            error_log("Allowing resource with common identifier: '$identifier'", 3, OMEKA_PATH . '/logs/duplicate-identifiers.log');
            return false;
        }
        
        // Search for items with the given identifier in the specified item set
        $searchParams = [
            'property' => [
                [
                    'property' => 10, // dcterms:identifier property ID
                    'type' => 'eq',
                    'text' => $identifier
                ]
            ],
            'item_set_id' => $itemSetId,
            'limit' => 1
        ];
        
        $response = $this->api()->search('items', $searchParams);
        $totalItems = $response->getTotalResults();
        
        if ($totalItems > 0) {
            $items = $response->getContent();
            $existingItem = $items[0];
            error_log("Found existing item with ID {$existingItem->id()} having identifier '$identifier'", 3, OMEKA_PATH . '/logs/duplicate-identifiers.log');
            return true;
        }
        
        error_log("No existing item found with identifier '$identifier' in item set #$itemSetId", 3, OMEKA_PATH . '/logs/duplicate-identifiers.log');
        return false;
    } catch (\Exception $e) {
        error_log("Error checking for existing identifier: " . $e->getMessage(), 3, OMEKA_PATH . '/logs/duplicate-identifiers.log');
        return false; // Assume no duplicate in case of error
    }
}

/**
 * Extract the identifier from item data
 *
 * @param array $itemData The item data array
 * @return string|null The identifier or null if not found
 */
private function extractIdentifierFromItemData($itemData) {
    if (isset($itemData['dcterms:identifier'])) {
        foreach ($itemData['dcterms:identifier'] as $identifierData) {
            if (isset($identifierData['@value'])) {
                return $identifierData['@value'];
            }
        }
    }
    return null;
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

// Update the searchAction method to handle all filters
public function searchAction()
{
    $request = $this->getRequest();
    $searchQuery = $request->getQuery('query', '');
    $searchType = $request->getQuery('type', 'all'); // 'items', 'item_sets', or 'all'
    $page = $request->getQuery('page', 1);
    $perPage = 20;
    
    // Basic filters
    $filterShape = $request->getQuery('shape', '');
    $filterVariant = $request->getQuery('variant', '');
    $filterMaterial = $request->getQuery('material', '');
    $filterElongation = $request->getQuery('elongation', '');
    
    // Advanced filters - morphology
    $filterThickness = $request->getQuery('thickness', '');
    $filterBase = $request->getQuery('base', '');
    $filterCondition = $request->getQuery('condition', '');
    
    // Advanced filters - chipping
    $filterChippingMode = $request->getQuery('chippingMode', '');
    $filterChippingDirection = $request->getQuery('chippingDirection', '');
    $filterChippingDelineation = $request->getQuery('chippingDelineation', '');
    $filterChippingShape = $request->getQuery('chippingShape', '');
    $filterChippingAmplitude = $request->getQuery('chippingAmplitude', '');
    
    // Measurement range filters
    $minHeight = $request->getQuery('minHeight', '');
    $maxHeight = $request->getQuery('maxHeight', '');
    $minWidth = $request->getQuery('minWidth', '');
    $maxWidth = $request->getQuery('maxWidth', '');
    $minThickness = $request->getQuery('minThickness', '');
    $maxThickness = $request->getQuery('maxThickness', '');
    $minWeight = $request->getQuery('minWeight', '');
    $maxWeight = $request->getQuery('maxWeight', '');
    
    $results = [];
    $totalItems = 0;
    $totalItemSets = 0;
    
    $hasFilters = $filterShape || $filterVariant || $filterMaterial || $filterElongation || 
                  $filterThickness || $filterBase || $filterCondition || $filterChippingMode || 
                  $filterChippingDirection || $filterChippingDelineation || $filterChippingShape || 
                  $filterChippingAmplitude || $minHeight || $maxHeight || $minWidth || $maxWidth || 
                  $minThickness || $maxThickness || $minWeight || $maxWeight;
    
    if ($searchQuery || $hasFilters) {
        // Search for item sets if search type is 'all' or 'item_sets'
        if ($searchType === 'all' || $searchType === 'item_sets') {
            $itemSetQuery = [];
            
            // Basic search query
            if ($searchQuery) {
                $itemSetQuery['fulltext_search'] = $searchQuery;
            }
            
            // Execute the item sets search
            $itemSetsResponse = $this->api()->search('item_sets', $itemSetQuery);
            $results['item_sets'] = $itemSetsResponse->getContent();
            $totalItemSets = $itemSetsResponse->getTotalResults();
        }
        
        // Search for items if search type is 'all' or 'items'
        if ($searchType === 'all' || $searchType === 'items') {
            $itemQuery = [];
            
            // Basic search query
            if ($searchQuery) {
                $itemQuery['fulltext_search'] = $searchQuery;
            }
            
            // Apply property filters for arrowheads
            $propertyFilters = [];
            
            // Basic filters
            if ($filterShape) {
                $propertyFilters[] = [
                    'property' => 7651,  // Arrowhead Shape property ID
                    'type' => 'eq',
                    'text' => $filterShape
                ];
            }
            
            if ($filterVariant) {
                $propertyFilters[] = [
                    'property' => 7652,  // Arrowhead Variant property ID
                    'type' => 'eq',
                    'text' => $filterVariant
                ];
            }
            
            if ($filterMaterial) {
                $propertyFilters[] = [
                    'property' => 4633,  // Material property ID
                    'type' => 'eq',
                    'text' => $filterMaterial
                ];
            }
            
            if ($filterElongation) {
                $propertyFilters[] = [
                    'property' => 7676,  // Elongation Index property ID
                    'type' => 'eq',
                    'text' => $filterElongation
                ];
            }
            
            // Advanced filters - morphology
            if ($filterThickness) {
                $propertyFilters[] = [
                    'property' => 7677,  // Thickness Index property ID
                    'type' => 'eq',
                    'text' => $filterThickness
                ];
            }
            
            if ($filterBase) {
                $propertyFilters[] = [
                    'property' => 7653,  // Base Type property ID
                    'type' => 'eq',
                    'text' => $filterBase
                ];
            }
            
            if ($filterCondition !== '') {
                $propertyFilters[] = [
                    'property' => 476,  // Condition State property ID
                    'type' => 'eq',
                    'text' => $filterCondition
                ];
            }
            
            // Advanced filters - chipping
            if ($filterChippingMode) {
                $propertyFilters[] = [
                    'property' => 7656,  // Chipping Mode property ID
                    'type' => 'eq',
                    'text' => $filterChippingMode
                ];
            }
            
            if ($filterChippingDirection) {
                $propertyFilters[] = [
                    'property' => 7658,  // Chipping Direction property ID
                    'type' => 'eq',
                    'text' => $filterChippingDirection
                ];
            }
            
            if ($filterChippingDelineation) {
                $propertyFilters[] = [
                    'property' => 7660,  // Chipping Delineation property ID
                    'type' => 'eq',
                    'text' => $filterChippingDelineation
                ];
            }
            
            if ($filterChippingShape) {
                $propertyFilters[] = [
                    'property' => 7661,  // Chipping Shape property ID
                    'type' => 'eq',
                    'text' => $filterChippingShape
                ];
            }
            
            if ($filterChippingAmplitude !== '') {
                $propertyFilters[] = [
                    'property' => 7657,  // Chipping Amplitude property ID
                    'type' => 'eq',
                    'text' => $filterChippingAmplitude
                ];
            }
            
            // Measurement range filters - we'll use regex to extract the numeric parts
            if ($minHeight) {
                $propertyFilters[] = [
                    'property' => 5616,  // Height property ID
                    'type' => 'gte',
                    'text' => $minHeight
                ];
            }
            
            if ($maxHeight) {
                $propertyFilters[] = [
                    'property' => 5616,  // Height property ID
                    'type' => 'lte',
                    'text' => $maxHeight
                ];
            }
            
            if ($minWidth) {
                $propertyFilters[] = [
                    'property' => 5688,  // Width property ID
                    'type' => 'gte',
                    'text' => $minWidth
                ];
            }
            
            if ($maxWidth) {
                $propertyFilters[] = [
                    'property' => 5688,  // Width property ID
                    'type' => 'lte',
                    'text' => $maxWidth
                ];
            }
            
            if ($minThickness) {
                $propertyFilters[] = [
                    'property' => 7244,  // Thickness property ID
                    'type' => 'gte',
                    'text' => $minThickness
                ];
            }
            
            if ($maxThickness) {
                $propertyFilters[] = [
                    'property' => 7244,  // Thickness property ID
                    'type' => 'lte',
                    'text' => $maxThickness
                ];
            }
            
            if ($minWeight) {
                $propertyFilters[] = [
                    'property' => 5779,  // Weight property ID
                    'type' => 'gte',
                    'text' => $minWeight
                ];
            }
            
            if ($maxWeight) {
                $propertyFilters[] = [
                    'property' => 5779,  // Weight property ID
                    'type' => 'lte',
                    'text' => $maxWeight
                ];
            }
            
            // Add property filters if any
            if (!empty($propertyFilters)) {
                $itemQuery['property'] = $propertyFilters;
            }
            
            // Execute the items search
            $itemsResponse = $this->api()->search('items', $itemQuery);
            $results['items'] = $itemsResponse->getContent();
            $totalItems = $itemsResponse->getTotalResults();
        }
    }
    
    $totalResults = $totalItems + $totalItemSets;
    
    return new ViewModel([
        'site' => $this->currentSite(),
        'searchQuery' => $searchQuery,
        'searchType' => $searchType,
        'results' => $results,
        'totalResults' => $totalResults,
        'totalItems' => $totalItems,
        'totalItemSets' => $totalItemSets
    ]);
}
    

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
    $relatedItems = []; // Initialize here
    
    try {
        if ($resourceType === 'item_set') {
            $resource = $this->api()->read('item_sets', $id)->getContent();
            
            error_log("=== DEBUGGING ITEM SET $id ===", 3, OMEKA_PATH . '/logs/related-items-debug.log');
            error_log("Item set title: " . $resource->displayTitle(), 3, OMEKA_PATH . '/logs/related-items-debug.log');
            
            // Try different search approaches
            
            // Approach 1: Direct item_set_id search
            $searchParams1 = [
                'item_set_id' => $id,
                'sort_by' => 'created',
                'sort_order' => 'desc',
                'per_page' => 50
            ];
            
            error_log("Search params 1: " . print_r($searchParams1, true), 3, OMEKA_PATH . '/logs/related-items-debug.log');
            
            $response1 = $this->api()->search('items', $searchParams1);
            $relatedItems = $response1->getContent();
            $totalResults1 = $response1->getTotalResults();
            
            error_log("Approach 1 - Found $totalResults1 items using item_set_id", 3, OMEKA_PATH . '/logs/related-items-debug.log');
            
            // Approach 2: If no results, try searching all items and filter
            if (empty($relatedItems)) {
                error_log("Trying approach 2 - searching all items", 3, OMEKA_PATH . '/logs/related-items-debug.log');
                
                $allItemsResponse = $this->api()->search('items', ['per_page' => 100]);
                $allItems = $allItemsResponse->getContent();
                
                error_log("Total items in system: " . count($allItems), 3, OMEKA_PATH . '/logs/related-items-debug.log');
                
                foreach ($allItems as $item) {
                    $itemSets = $item->itemSets();
                    foreach ($itemSets as $itemSet) {
                        if ($itemSet->id() == $id) {
                            $relatedItems[] = $item;
                            error_log("Found item: " . $item->displayTitle() . " (ID: " . $item->id() . ")", 3, OMEKA_PATH . '/logs/related-items-debug.log');
                        }
                    }
                }
            }
            
            error_log("Final count of related items: " . count($relatedItems), 3, OMEKA_PATH . '/logs/related-items-debug.log');
            
        } else {
            $resource = $this->api()->read('items', $id)->getContent();
        }
        
        // Get all values using the proper Omeka S method
        $values = $resource->values();
        
        foreach ($values as $term => $propertyData) {
            try {
                // Skip empty data
                if (empty($propertyData)) {
                    continue;
                }
                
                $propertyLabel = $this->getHumanReadableLabel($term);
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
    
    error_log("Passing " . count($relatedItems) . " related items to view", 3, OMEKA_PATH . '/logs/related-items-debug.log');
    
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
        'ah:hasMorphology' => 'Morphology',
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

    // download data functionality
    public function downloadTtlAction()
{
    $id = $this->params()->fromQuery('id');
    $type = $this->params()->fromQuery('type', 'item');
    
    if (empty($id)) {
        return $this->redirect()->toRoute('site/add-triplestore/search', ['site-slug' => $this->currentSite()->slug()]);
    }
    
    // Determine API endpoint based on resource type
    $resourceType = $type === 'item_set' ? 'item_sets' : 'items';
    $api = $this->api();
    
    try {
        // Get the resource
        $resource = $api->read($resourceType, $id)->getContent();
        
        // Generate TTL for the main resource
        $ttlData = $this->generateTtlForResource($resource, $type);
        
        // If it's an item set, also include all its items
        if ($type === 'item_set') {
            $items = $api->search('items', ['item_set_id' => $id])->getContent();
            foreach ($items as $item) {
                $ttlData .= $this->generateTtlForResource($item, 'item');
                
                // Also include media for each item
                $media = $api->search('media', ['item_id' => $item->id()])->getContent();
                foreach ($media as $medium) {
                    $ttlData .= $this->generateTtlForResource($medium, 'media');
                }
            }
        }
        
        // Set response headers for download
        $filename = $resource->displayTitle();
        $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename); // Sanitize filename
        
        $response = $this->getResponse();
        $response->getHeaders()->addHeaderLine('Content-Type', 'text/turtle; charset=UTF-8');
        $response->getHeaders()->addHeaderLine('Content-Disposition', 'attachment; filename="' . $filename . '.ttl"');
        $response->setContent($ttlData);
        
        return $response;
    } catch (\Exception $e) {
        $this->messenger()->addError('Error generating TTL data: ' . $e->getMessage());
        return $this->redirect()->toRoute('site/add-triplestore/view-details', 
            ['site-slug' => $this->currentSite()->slug()],
            ['query' => ['id' => $id, 'type' => $type]]
        );
    }
}

private function generateTtlForResource($resource, $type)
{
    $ttl = '';
    
    // Create the subject URI based on resource type and ID
    $baseUrl = $this->url()->fromRoute('top', [], ['force_canonical' => true]);
    $baseUrl = rtrim($baseUrl, '/');
    $subjectUri = $baseUrl . '/' . ($type === 'item_set' ? 'item-set' : ($type === 'item' ? 'item' : 'media')) . '/' . $resource->id();
    
    $ttl .= "# Resource: " . $resource->displayTitle() . "\n";
    $ttl .= "<{$subjectUri}>\n";
    
    // Add common properties
    $ttl .= "    a <http://www.w3.org/ns/ldp#Resource> ;\n";
    $ttl .= "    <http://purl.org/dc/terms/title> \"" . $this->escapeTtlString($resource->displayTitle()) . "\" ;\n";
    
    if ($resource->displayDescription()) {
        $ttl .= "    <http://purl.org/dc/terms/description> \"" . $this->escapeTtlString($resource->displayDescription()) . "\" ;\n";
    }
    
    // Add all other properties
    $values = $resource->values();
    $propertiesAdded = false;
    
    foreach ($values as $term => $propertyValues) {
        foreach ($propertyValues['values'] as $value) {
            $propertiesAdded = true;
            $val = $value->value();
            $uri = $value->uri();
            
            if ($uri) {
                $ttl .= "    <{$term}> <{$uri}> ;\n";
            } else {
                // Escape special characters in string values
                $ttl .= "    <{$term}> \"" . $this->escapeTtlString($val) . "\" ;\n";
            }
        }
    }
    
    // Add relationships
    if ($type === 'item_set') {
        $ttl .= "    <http://www.w3.org/ns/ldp#contains> ";
        $api = $this->api();
        $items = $api->search('items', ['item_set_id' => $resource->id()])->getContent();
        
        $itemUris = [];
        foreach ($items as $item) {
            $itemUris[] = "<{$baseUrl}/item/{$item->id()}>";
        }
        
        if (count($itemUris) > 0) {
            $ttl .= implode(", ", $itemUris) . " ;\n";
        } else {
            // Remove last semicolon and newline if no items
            $ttl = rtrim($ttl, ";\n") . " ;\n";
        }
    } elseif ($type === 'item') {
        // Add item set references for items
        $itemSets = $resource->itemSets();
        if (!empty($itemSets)) {
            $ttl .= "    <http://purl.org/dc/terms/isPartOf> ";
            $setUris = [];
            foreach ($itemSets as $itemSet) {
                $setUris[] = "<{$baseUrl}/item-set/{$itemSet->id()}>";
            }
            $ttl .= implode(", ", $setUris) . " ;\n";
        }
        
        // Add media references
        $api = $this->api();
        $media = $api->search('media', ['item_id' => $resource->id()])->getContent();
        if (!empty($media)) {
            $ttl .= "    <http://purl.org/dc/terms/hasPart> ";
            $mediaUris = [];
            foreach ($media as $medium) {
                $mediaUris[] = "<{$baseUrl}/media/{$medium->id()}>";
            }
            $ttl .= implode(", ", $mediaUris) . " ;\n";
        }
    } elseif ($type === 'media') {
        // Add reference to parent item
        $ttl .= "    <http://purl.org/dc/terms/isPartOf> <{$baseUrl}/item/{$resource->item()->id()}> ;\n";
        
        // Add media-specific data like file URL
        $ttl .= "    <http://purl.org/dc/terms/source> <" . $resource->originalUrl() . "> ;\n";
    }
    
    // Replace the last semicolon with a period
    $ttl = rtrim($ttl, ";\n") . " .\n\n";
    
    return $ttl;
}

/**
 * Escape special characters in TTL strings
 */
private function escapeTtlString($string)
{
    return str_replace(
        ['"', '\\', "\n", "\r", "\t"],
        ['\"', '\\\\', '\\n', '\\r', '\\t'],
        $string
    );
}
}