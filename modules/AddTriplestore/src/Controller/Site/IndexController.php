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

    // Updated function to process arrowhead form data with new namespace prefixes and structure
    private function processArrowheadFormData($formData, $itemSetId)
    {
        // Generate a base URI for resources
        $baseUri = $this->baseDataGraphUri;
        
        // Use the provided excavation ID or default to "0"
        $graphId = $itemSetId ?: "0";
        $baseUri .= $graphId . "/";
        
        // Generate a unique ID for the arrowhead if not provided
        $arrowheadId = !empty($formData['arrowhead_identifier']) 
            ? $formData['arrowhead_identifier'] 
            : 'AH-' . uniqid();
        
        // Create resource URIs
        $arrowheadUri = "$baseUri/item/$arrowheadId";
        $morphologyUri = "$baseUri/morphology/$arrowheadId";
        $chippingUri = "$baseUri/chipping/$arrowheadId";
        $heightUri = "$baseUri/typometry/{$arrowheadId}-height";
        $widthUri = "$baseUri/typometry/{$arrowheadId}-width";
        $thicknessUri = "$baseUri/typometry/{$arrowheadId}-thickness";
        $bodyLengthUri = "$baseUri/typometry/{$arrowheadId}-bodyLength";
        $baseLengthUri = "$baseUri/typometry/{$arrowheadId}-baseLength";
        $weightUri = "$baseUri/weight/$arrowheadId";
        $coordsUri = "$baseUri/coordinates/$arrowheadId";
        $depthUri = "$baseUri/depth/$arrowheadId";
        $encounterUri = "$baseUri/encounter/$arrowheadId";
        
        // Build TTL data with updated namespaces
        $ttl = $this->getTtlPrefixes();
        
        // Add arrowhead
        $ttl .= "<$arrowheadUri> a ah:Arrowhead, excav:Item;\n";
        $ttl .= "    dct:identifier \"$arrowheadId\"^^xsd:literal;\n";
        
        // Add shape if selected
        if (!empty($formData['arrowhead_shape'])) {
            $shapeSafe = $this->sanitizeForUri($formData['arrowhead_shape']);
            $ttl .= "    ah:shape <https://purl.org/megalod/kos/ah-shape/$shapeSafe>;\n";
        }
        
        // Add E55_Type (Elongate=True;Short=False)
        if (!empty($formData['arrowhead_type'])) {
            $value = stripos($formData['arrowhead_type'], 'true') !== false ? "true" : "false";
            $ttl .= "    crm:E55_Type \"$value\"^^xsd:boolean;\n";
        }
        
        // Add variant if selected
        if (!empty($formData['arrowhead_variant'])) {
            $variantSafe = $this->sanitizeForUri($formData['arrowhead_variant']);
            $ttl .= "    ah:variant <https://purl.org/megalod/kos/ah-variant/$variantSafe>;\n";
        }
        
        // Add material reference
        if (!empty($formData['arrowhead_material'])) {
            $ttl .= "    crm:E57_Material <http://collection.britishmuseum.org/id/thesaurus/material/" . $this->sanitizeForUri($formData['arrowhead_material']) . ">;\n";
        }
        
        // Add condition state
        if (!empty($formData['condition_state'])) {
            $value = stripos($formData['condition_state'], 'true') !== false ? "true" : "false";
            $ttl .= "    crm:E3_Condition_State \"$value\"^^xsd:boolean;\n";
        }
        
        // Add annotation if provided
        if (!empty($formData['arrowhead_annotation'])) {
            $ttl .= "    dbo:Annotation \"" . $formData['arrowhead_annotation'] . "\"^^xsd:literal;\n";
        }
        
        // Add typometry measurements
        if (!empty($formData['height'])) {
            $ttl .= "    schema:height <$heightUri>;\n";
        }
        
        if (!empty($formData['width'])) {
            $ttl .= "    schema:width <$widthUri>;\n";
        }
        
        if (!empty($formData['thickness'])) {
            $ttl .= "    schema:depth <$thicknessUri>;\n";
        }
        
        if (!empty($formData['weight'])) {
            $ttl .= "    schema:weight <$weightUri>;\n";
        }
        
        // Add elongation and thickness indices if provided
        if (!empty($formData['elongation_index'])) {
            $elongationSafe = $this->sanitizeForUri($formData['elongation_index']);
            $ttl .= "    excav:elongationIndex <https://purl.org/megalod/kos/MegaLOD-IndexElongation/$elongationSafe>;\n";
        }
        
        if (!empty($formData['thickness_index'])) {
            $thicknessSafe = $this->sanitizeForUri($formData['thickness_index']);
            $ttl .= "    excav:thicknessIndex <https://purl.org/megalod/kos/MegaLOD-IndexThickness/$thicknessSafe>;\n";
        }
        
        // Add coordinates if both latitude and longitude are provided
        if (!empty($formData['latitude']) && !empty($formData['longitude'])) {
            $ttl .= "    excav:hasCoordinatesInSquare <$coordsUri>;\n";
        }
        
        // Add references to morphology and additional properties
        $ttl .= "    ah:hasMorphology <$morphologyUri>;\n";
        
        if (!empty($formData['body_length'])) {
            $ttl .= "    ah:bodyLength <$bodyLengthUri>;\n";
        }
        
        if (!empty($formData['base_length'])) {
            $ttl .= "    ah:baseLength <$baseLengthUri>;\n";
        }
        
        // Add chipping information if relevant fields are provided
        if (!empty($formData['chipping_mode']) || 
            !empty($formData['chipping_amplitude']) || 
            !empty($formData['chipping_direction'])) {
            $ttl .= "    ah:hasChipping <$chippingUri>;\n";
        }
        
        $ttl .= "    .\n\n";
        
        // Add morphology
        $ttl .= "<$morphologyUri> a ah:Morphology;\n";
        
        // Add point definition
        if (!empty($formData['point_definition'])) {
            $value = stripos($formData['point_definition'], 'true') !== false ? "true" : "false";
            $ttl .= "    ah:point \"$value\"^^xsd:boolean;\n";
        } else {
            $ttl .= "    ah:point \"true\"^^xsd:boolean;\n";
        }
        
        // Add body symmetry
        if (!empty($formData['body_symmetry'])) {
            $value = stripos($formData['body_symmetry'], 'true') !== false ? "true" : "false";
            $ttl .= "    ah:body \"$value\"^^xsd:boolean;\n";
        } else {
            $ttl .= "    ah:body \"true\"^^xsd:boolean;\n";
        }
        
        // Add base if selected
        if (!empty($formData['arrowhead_base'])) {
            $baseSafe = $this->sanitizeForUri($formData['arrowhead_base']);
            $ttl .= "    ah:base <https://purl.org/megalod/kos/ah-base/$baseSafe>;\n";
        }
        
        $ttl .= "    .\n\n";
        
        // Add typometry values
        if (!empty($formData['height'])) {
            $ttl .= "<$heightUri> a excav:TypometryValue;\n";
            $ttl .= "    schema:value \"" . $formData['height'] . "\"^^xsd:decimal;\n";
            $ttl .= "    schema:UnitCode <http://qudt.org/vocab/unit/MM>;\n";
            $ttl .= "    .\n\n";
        }
        
        if (!empty($formData['width'])) {
            $ttl .= "<$widthUri> a excav:TypometryValue;\n";
            $ttl .= "    schema:value \"" . $formData['width'] . "\"^^xsd:decimal;\n";
            $ttl .= "    schema:UnitCode <http://qudt.org/vocab/unit/MM>;\n";
            $ttl .= "    .\n\n";
        }
        
        if (!empty($formData['thickness'])) {
            $ttl .= "<$thicknessUri> a excav:TypometryValue;\n";
            $ttl .= "    schema:value \"" . $formData['thickness'] . "\"^^xsd:decimal;\n";
            $ttl .= "    schema:UnitCode <http://qudt.org/vocab/unit/MM>;\n";
            $ttl .= "    .\n\n";
        }
        
        if (!empty($formData['body_length'])) {
            $ttl .= "<$bodyLengthUri> a excav:TypometryValue;\n";
            $ttl .= "    schema:value \"" . $formData['body_length'] . "\"^^xsd:decimal;\n";
            $ttl .= "    schema:UnitCode <http://qudt.org/vocab/unit/MM>;\n";
            $ttl .= "    .\n\n";
        }
        
        if (!empty($formData['base_length'])) {
            $ttl .= "<$baseLengthUri> a excav:TypometryValue;\n";
            $ttl .= "    schema:value \"" . $formData['base_length'] . "\"^^xsd:decimal;\n";
            $ttl .= "    schema:UnitCode <http://qudt.org/vocab/unit/MM>;\n";
            $ttl .= "    .\n\n";
        }
        
        if (!empty($formData['weight'])) {
            $ttl .= "<$weightUri> a excav:Weight;\n";
            $ttl .= "    schema:value \"" . $formData['weight'] . "\"^^xsd:decimal;\n";
            $ttl .= "    schema:UnitCode <http://qudt.org/vocab/unit/GM>;\n";
            $ttl .= "    .\n\n";
        }
        
        // Add chipping details if necessary
        if (!empty($formData['chipping_mode']) || 
            !empty($formData['chipping_amplitude']) || 
            !empty($formData['chipping_direction'])) {
            
            $ttl .= "<$chippingUri> a ah:Chipping;\n";
            
            // Add chipping mode
            if (!empty($formData['chipping_mode'])) {
                $modeSafe = $this->sanitizeForUri($formData['chipping_mode']);
                $ttl .= "    ah:chippingMode <https://purl.org/megalod/kos/ah-chippingMode/$modeSafe>;\n";
            }
            
            // Add chipping amplitude
            if (!empty($formData['chipping_amplitude'])) {
                $value = stripos($formData['chipping_amplitude'], 'true') !== false ? "true" : "false";
                $ttl .= "    ah:chippingAmplitude \"$value\"^^xsd:boolean;\n";
            }
            
            // Add chipping direction
            if (!empty($formData['chipping_direction'])) {
                $directionSafe = $this->sanitizeForUri($formData['chipping_direction']);
                $ttl .= "    ah:chippingDirection <https://purl.org/megalod/kos/ah-chippingDirection/$directionSafe>;\n";
            }
            
            // Add chipping orientation
            if (!empty($formData['chipping_orientation'])) {
                $value = stripos($formData['chipping_orientation'], 'true') !== false ? "true" : "false";
                $ttl .= "    ah:chippingOrientation \"$value\"^^xsd:boolean;\n";
            }
            
            // Add chipping delineation
            if (!empty($formData['chipping_delineation'])) {
                $delineationSafe = $this->sanitizeForUri($formData['chipping_delineation']);
                $ttl .= "    ah:chippingDelineation <https://purl.org/megalod/kos/ah-chippingDelineation/$delineationSafe>;\n";
            }
            
            // Add lateral chipping locations
            $lateralLocations = [];
            if (!empty($formData['chipping_location_lateral_1'])) 
                $lateralLocations[] = $this->sanitizeForUri($formData['chipping_location_lateral_1']);
            if (!empty($formData['chipping_location_lateral_2'])) 
                $lateralLocations[] = $this->sanitizeForUri($formData['chipping_location_lateral_2']);
            if (!empty($formData['chipping_location_lateral_3'])) 
                $lateralLocations[] = $this->sanitizeForUri($formData['chipping_location_lateral_3']);
            
            if (!empty($lateralLocations)) {
                foreach ($lateralLocations as $location) {
                    $ttl .= "    ah:chippingLocationSide <https://purl.org/megalod/kos/ah-chippingLocation/$location>;\n";
                }
            }
            
            // Add transversal chipping locations
            $transversalLocations = [];
            if (!empty($formData['chipping_location_transversal_1'])) 
                $transversalLocations[] = $this->sanitizeForUri($formData['chipping_location_transversal_1']);
            if (!empty($formData['chipping_location_transversal_2'])) 
                $transversalLocations[] = $this->sanitizeForUri($formData['chipping_location_transversal_2']);
            if (!empty($formData['chipping_location_transversal_3'])) 
                $transversalLocations[] = $this->sanitizeForUri($formData['chipping_location_transversal_3']);
            
            if (!empty($transversalLocations)) {
                foreach ($transversalLocations as $location) {
                    $ttl .= "    ah:chippingLocationTransversal <https://purl.org/megalod/kos/ah-chippingLocation/$location>;\n";
                }
            }
            
            // Add chipping shape
            if (!empty($formData['chipping_shape'])) {
                $shapeSafe = $this->sanitizeForUri($formData['chipping_shape']);
                $ttl .= "    ah:chippingShape <https://purl.org/megalod/kos/ah-chippingShape/$shapeSafe>;\n";
            }
            
            $ttl .= "    .\n\n";
        }
        
        // Add coordinates if provided
        if (!empty($formData['latitude']) && !empty($formData['longitude'])) {
            $ttl .= "<$coordsUri> a excav:Coordinates;\n";
            $ttl .= "    geo:latitude \"" . $formData['latitude'] . "\"^^xsd:decimal;\n";
            $ttl .= "    geo:longitude \"" . $formData['longitude'] . "\"^^xsd:decimal;\n";
            
            // Add depth if provided
            if (!empty($formData['depth'])) {
                $ttl .= "    schema:depth <$depthUri>;\n";
            }
            
            $ttl .= "    .\n\n";
            
            // Add depth details if provided
            if (!empty($formData['depth'])) {
                $ttl .= "<$depthUri> a excav:Depth;\n";
                $ttl .= "    schema:value \"" . $formData['depth'] . "\"^^xsd:decimal;\n";
                $ttl .= "    schema:UnitCode <http://qudt.org/vocab/unit/CMNT>;\n";
                $ttl .= "    .\n\n";
            }
        }
        
        // Add encounter event if we have context information
        if ($itemSetId) {
            $ttl .= "<$encounterUri> a excav:EncounterEvent;\n";
            $ttl .= "    dct:date \"" . date('Y-m-d') . "\"^^xsd:literal;\n";
            $ttl .= "    crmsci:O19_encountered_object <$arrowheadUri>;\n";
            
            // Connect to excavation, context and SVU if available
            $excavationUri = $this->baseDataGraphUri . $graphId;
            $ttl .= "    excav:foundInExcavation <$excavationUri>;\n";
            
            // We'll assume the context and SVU information is available from the excavation data
            // This would need to be enhanced with actual context/SVU data in a real implementation
            $ttl .= "    .\n\n";
        }
        
        return $ttl;
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
                $result = $this->uploadTtlData($ttlData, $itemSetId);
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
        
        // Process the excavation form submission
        if ($uploadType == 'excavation' && !isset($_FILES['file'])) {
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
            $excavationIdentifier = $this->params()->fromPost('excavation_id');
    
            // If no dedicated excavation ID found, generate a new one with proper prefix
            if (empty($excavationIdentifier)) {
                $excavationIdentifier = 'EXC-' . uniqid();
            }
    
            // Convert form data to TTL
            $ttlData = $this->prepareTtlFromExcavationData(
                $excavationIdentifier, 
                $excavationData, 
                $contextData, 
                $svuData, 
                $encounterData
            );
            $excavationIdentifier = str_replace(' ', '_', $excavationIdentifier);
            error_log('Creating item set with title: Excavation ' . $excavationIdentifier, 3, OMEKA_PATH . '/logs/title-debug.log');
            try {
            
                $itemSetData = [
                    'dcterms:title' => [
                        [
                            'type' => 'literal',
                            'property_id' => 1,
                            '@value' => "Excavation (Temporary)"
                        ]
                    ],
                    'dcterms:description' => [
                        [
                            'type' => 'literal',
                            'property_id' => 4,
                            '@value' => "Item set for excavation with identifier $excavationIdentifier"
                        ]
                    ],
                    'o:is_public' => true
                ];
                

                // Create the item set
                $itemSetResponse = $this->api()->create('item_sets', $itemSetData);
                $itemSetId = $itemSetResponse->getContent()->id();

                // Now update the title to include the item set ID
                $newTitle = "Excavation EXC-$itemSetId";
                error_log('Updating item set with new title: ' . $newTitle, 3, OMEKA_PATH . '/logs/title-debug.log');

                // Update the item set with the new title
                $updateResult = $this->api()->update('item_sets', $itemSetId, [
                    'dcterms:title' => [
                        [
                            'type' => 'literal',
                            'property_id' => 1, 
                            '@value' => $newTitle
                        ]
                    ]
                ], [], ['isPartial' => true]);
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
 * Update item set metadata with excavation information
 * 
 * @param int $itemSetId The item set ID to update
 * @param array $excavationData The excavation data to add to the item set
 * @return bool Success status
 */
private function updateItemSetWithExcavationInfo($itemSetId, $excavationData) {
    if (!$itemSetId || empty($excavationData)) {
        return false;
    }
    
    try {
        // First, retrieve the current item set data
        $itemSet = $this->api()->read('item_sets', $itemSetId)->getContent();
        
        // Prepare the update data
        $updateData = [
            'dcterms:title' => [
                [
                    'type' => 'literal',
                    'property_id' => 1,
                    '@value' => "Excavation " . $excavationData['identifier']
                ]
            ]
        ];
        
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
                    'property_id' => 7665, // Dublin Core Creator
                    '@value' => $excavationData['archaeologist']
                ]
            ];
        }
        
        // Add GPS coordinates if available
        if (!empty($excavationData['gps'])) {
            $updateData['dcterms:coverage'] = [
                [
                    'type' => 'literal',
                    'property_id' => 18, // Dublin Core Coverage
                    '@value' => $excavationData['gps']
                ]
            ];
        }
        
        // Add location details if available
        if (!empty($excavationData['location_details'])) {
            if (!isset($updateData['dcterms:coverage'])) {
                $updateData['dcterms:coverage'] = [];
            }
            
            $updateData['dcterms:coverage'][] = [
                'type' => 'literal',
                'property_id' => 7664, // Dublin Core Coverage
                '@value' => $excavationData['location_details']
            ];
        }
        
        // Add date range if available
        if (!empty($excavationData['date_range'])) {
            $updateData['dcterms:temporal'] = [
                [
                    'type' => 'literal',
                    'property_id' => 3, // Appropriate property ID for temporal
                    '@value' => $excavationData['date_range']
                ]
            ];
        }
        
        // Log what we're updating
        error_log('Updating item set ' . $itemSetId . ' with excavation info: ' . print_r($updateData, true), 3, OMEKA_PATH . '/logs/excavation-update.log');
        
        // Execute the update
        $updateResult = $this->api()->update(
            'item_sets', 
            $itemSetId, 
            $updateData, 
            [], 
            ['isPartial' => true]
        );
        
        return $updateResult ? true : false;
        
    } catch (\Exception $e) {
        error_log('Failed to update item set with excavation info: ' . $e->getMessage(), 3, OMEKA_PATH . '/logs/excavation-update.log');
        return false;
    }
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

public function processCollectingFormAction()
{
    // Get the item set ID and upload type from query parameters
    $itemSetId = $this->params()->fromQuery('item_set_id');
    $uploadType = $this->params()->fromQuery('upload_type', 'arrowhead');
    
    // Get all POST data from the collecting form
    $formData = $this->params()->fromPost();
    
    error_log('Received collecting form data: ' . print_r($formData, true), 3, OMEKA_PATH . '/logs/collecting-form.log');
    
    // Transform collecting form data to format expected by processArrowheadFormData
    $arrowheadData = $this->transformCollectingFormToArrowheadData($formData);
    
    // Process the transformed data
    if (!empty($arrowheadData)) {
        $ttlData = $this->processArrowheadFormData($arrowheadData, $itemSetId);
        $result = $this->uploadTtlData($ttlData, $itemSetId);
        
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
 */
private function transformCollectingFormToArrowheadData($formData)
{
    $arrowheadData = [];
    
    // Correct mapping based on the actual form structure
    // Map new collecting form prompts to arrowhead fields
    $fieldMappings = [
        'prompt_1'  => 'arrowhead_identifier',           // Identifier must be unique.
        'prompt_3'  => 'arrowhead_annotation',           // Item's observations and details.
        'prompt_4'  => 'condition_state',                // Condition State (Complete=True; Broken=False)
        'prompt_6'  => 'weight',                         // Item's weight (with no units)
        'prompt_7'  => 'weight_unit',                    // Unit of the Weight (URI)
        'prompt_8'  => 'height',                         // Item's length
        'prompt_9'  => 'height_unit',                    // Unit of the length (URI)
        'prompt_10' => 'width',                          // Item's width
        'prompt_11' => 'width_unit',                     // Unit of the width (URI)
        'prompt_12' => 'thickness',                      // Item's thickness
        'prompt_13' => 'thickness_unit',                 // Unit of the thickness (URI)
        'prompt_14' => 'arrowhead_type',                 // Type of the arrowhead (Elongate=True;Short=False)
        'prompt_16' => 'elongation_index',               // Elongation Index of the Item
        'prompt_18' => 'latitude',                       // Was found in the Coordinates (Y coordinate)
        'prompt_19' => 'longitude',                      // Was found in the Coordinates (X coordinate)
        'prompt_20' => 'depth',                          // Was found in the Coordinates (Depth)
        'prompt_21' => 'arrowhead_material',             // Arrowhead is made of (material uri)
        'prompt_22' => 'gps_latitude',                   // GPS coordinates (Latitude)
        'prompt_23' => 'gps_longitude',                  // GPS coordinates (Longitude)
        'prompt_24' => 'arrowhead_variant',              // Variant of the arrowhead
        'prompt_26' => 'arrowhead_shape',                // Shape of the arrowhead
        'prompt_28' => 'point_definition',               // Definition of the tip (Pinquant=True; Fractured=False)
        'prompt_30' => 'body_symmetry',                  // Body symmetry (Symmetrical=True; Non-symmetrical=False)
        'prompt_32' => 'arrowhead_base',                 // Type of the base
        'prompt_34' => 'body_length',                    // BodyLength (in mm)
        'prompt_35' => 'base_length',                    // BaseLength (in mm)
        'prompt_36' => 'chipping_mode',                  // Chipping-mode
        'prompt_38' => 'chipping_amplitude',             // Chipping-amplitude (Marginal=True, Deep=False)
        'prompt_40' => 'chipping_direction',             // Chipping-direction
        'prompt_42' => 'chipping_orientation',           // Chipping-orientation (Side=True, Transversal=False)
        'prompt_44' => 'chipping_delineation',           // Chipping-delineation
        'prompt_46' => 'chipping_location_lateral_1',    // Chipping-location-Lateral (1)
        'prompt_48' => 'chipping_location_lateral_2',    // Chipping-location-Lateral (2)
        'prompt_50' => 'chipping_location_lateral_3',    // Chipping-location-Lateral (3)
        'prompt_52' => 'chipping_location_transversal_1',// Chipping-Location-Transversal (1)
        'prompt_54' => 'chipping_location_transversal_2',// Chipping-Location-Transversal (2)
        'prompt_56' => 'chipping_location_transversal_3',// Chipping-Location-Transversal (3)
        'prompt_58' => 'chipping_shape',                 // Chipping-Shape
    ];
    
    // Process the mapping
    foreach ($fieldMappings as $collectingField => $arrowheadField) {
        if (isset($formData[$collectingField])) {
            $arrowheadData[$arrowheadField] = $formData[$collectingField];
        }
    }
    
    return $arrowheadData;
}

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
    $ttl .= "<$excavationUri> a crmarchaeo:A9_Archaeological_Excavation;\n";
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
    $ttl .= "<$contextUri> a crmarchaeo:A1_Excavation_Processing_Unit;\n";
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
        
        // Add district and parish if provided
        if (!empty($excavationData['district'])) {
            $districtUri = "$baseUri$graphId/district/" . $this->sanitizeForUri($excavationData['district']);
            $ttl .= "    dbo:district <$districtUri>;\n";
        }
        
        if (!empty($excavationData['parish'])) {
            $parishUri = "$baseUri$graphId/parish/" . $this->sanitizeForUri($excavationData['parish']);
            $ttl .= "    dbo:parish <$parishUri>;\n";
        }
        
        if (!empty($excavationData['country'])) {
            $countryUri = "http://dbpedia.org/resource/" . $this->sanitizeForUri($excavationData['country']);
            $ttl .= "    dbo:country <$countryUri>;\n";
        }
        
        $ttl .= "    .\n\n";
        
        // Add GPS coordinates if provided
        if (!empty($excavationData['latitude']) && !empty($excavationData['longitude'])) {
            $ttl .= "<$gpsUri> a excav:GPSCoordinates;\n";
            $ttl .= "    geo:lat " . $excavationData['latitude'] . ";\n";
            $ttl .= "    geo:long " . $excavationData['longitude'] . ";\n";
            $ttl .= "    .\n\n";
        }
        
        // Add district if provided
        if (!empty($excavationData['district'])) {
            $ttl .= "<$districtUri> a dbo:District;\n";
            $ttl .= "    dbo:informationName \"" . $excavationData['district'] . "\"^^xsd:literal;\n";
            $ttl .= "    .\n\n";
        }
        
        // Add parish if provided
        if (!empty($excavationData['parish'])) {
            $ttl .= "<$parishUri> a dbo:Parish;\n";
            $ttl .= "    dbo:informationName \"" . $excavationData['parish'] . "\"^^xsd:literal;\n";
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
        $ttl .= "<$svuUri> a crmarchaeo:A2_Stratigraphic_Volume_Unit;\n";
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
                
                // Use the new BCAC URI structure
                $bcacValue = $svuData['data']['lower_bc'] ? "BC" : "AC";
                $ttl .= "    excav:bcac <https://purl.org/megalod/kos/MegaLOD-BCAC/$bcacValue>;\n";
                $ttl .= "    .\n\n";
            }
            
            if (!empty($svuData['data']['upper_year'])) {
                $ttl .= "<$upperInstantUri> a excav:Instant;\n";
                $ttl .= "    time:inXSDgYear \"" . $svuData['data']['upper_year'] . "\"^^xsd:gYear;\n";
                
                // Use the new BCAC URI structure
                $bcacValue = $svuData['data']['upper_bc'] ? "BC" : "AC";
                $ttl .= "    excav:bcac <https://purl.org/megalod/kos/MegaLOD-BCAC/$bcacValue>;\n";
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

    // log ttl data
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
        error_log('Validation for excavation, this is an arrwohead', 3, OMEKA_PATH . '/logs/auxNew.log');
        error_log('Validation for excavation failed: ' . $e->getMessage(), 3, OMEKA_PATH . '/logs/excavation-debug.log');
        
        // Check if this item belongs to an excavation item set
        if ($itemSetId) {
            error_log('Item set ID provided: ' . $itemSetId, 3, OMEKA_PATH . '/logs/auxNew.log');
            $excavationId = $this->getExcavationIdentifierFromItemSet($itemSetId);
            error_log('Item set ID: ' . $itemSetId, 3, OMEKA_PATH . '/logs/bbbbbbbb.log');
            if ($excavationId) {
                $excavationIdentifier = $excavationId;
                $graphUri = $this->baseDataGraphUri . $excavationId . "/";
                error_log('Using excavation ID from item set: ' . $excavationIdentifier, 3, OMEKA_PATH . '/logs/excavation-debug.log');
            }
        }
    }

    if ($itemSetId) { // If itemSetId is provided, normalize URIs of the data which is not excavation
        $ttlData = $this->normalizeUris($ttlData, $itemSetId);
        error_log('URIs normalized for item set ID: ' . $itemSetId, 3, OMEKA_PATH . '/logs/uri-normalize.log');
    }

    // normalize also when is excavation
    if ($isExcavation && !$itemSetId) {
        try {
            // Create a new item set directly using the API manager
            $response = $this->api()->create('item_sets', [
                'dcterms:title' => [
                    [
                        'type' => 'literal',
                        'property_id' => 1,
                        '@value' => "Excavation " . ($excavationIdentifier ?: "New")
                    ]
                ],
                'dcterms:description' => [
                    [
                        'type' => 'literal',
                        'property_id' => 4,
                        '@value' => "Item set for excavation " . ($excavationIdentifier ?: "")
                    ]
                ],
                'o:is_public' => true
            ]);
            
            // If successful, get the new item set ID
            if ($response) {
                $newItemSet = $response->getContent();
                $itemSetId = $newItemSet->id();
                
                error_log('Successfully created item set with ID: ' . $itemSetId, 3, OMEKA_PATH . '/logs/excavation-debug.log');
                
                // Now normalize the URIs with the new item set ID
                $ttlData = $this->normalizeUris($ttlData, $itemSetId);
                error_log('URIs normalized for excavation with item set ID: ' . $itemSetId, 3, OMEKA_PATH . '/logs/uri-normalize.log');
                
                // Store the mapping between item set and excavation
                if ($excavationIdentifier) {
                    $this->storeMappingBetweenItemSetAndExcavation($itemSetId, $excavationIdentifier);
                }
            }
        } catch (\Exception $e) {
            error_log('Error creating item set for excavation: ' . $e->getMessage(), 3, OMEKA_PATH . '/logs/excavation-debug.log');
        }
    }

    if ($isExcavation && $excavationIdentifier) {
        if ($this->excavationIdentifierExists($excavationIdentifier)) {
            /*$errorMessage = 'An excavation with identifier "' . $excavationIdentifier . '" already exists. Please use a different identifier.';
            error_log('Excavation identifier already exists: ' . $excavationIdentifier, 3, OMEKA_PATH . '/logs/excavation-debug.log');
            
            // Add error message to messenger
            $messenger = $this->messenger();
            $messenger->addError($errorMessage);
            
            // Return a simple error indicator
            return 'Error: Duplicate excavation identifier';*/
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
        error_log(' this is an item that is not excavation', 3, OMEKA_PATH . '/logs/auxNew.log');
        // This is an arrowhead or other item being added to an existing excavation
        // Retrieve the excavation identifier associated with this item set
        error_log('Attempting to retrieve excavation identifier for item set: ' . $itemSetId, 3, OMEKA_PATH . '/logs/excavation-debug.log');
        $excavationIdentifier = $this->getExcavationIdentifierFromItemSet($itemSetId);
        error_log('Retrieved excavation identifier for item set ' . $itemSetId . ': ' . $itemSetId, 3, OMEKA_PATH . '/logs/excavation-debug.log');
    }
    
    // Now proceed with the regular upload process
    // First, upload to GraphDB with the excavation identifier if available
    $graphDbResult = $this->sendToGraphDB($ttlData, $itemSetId);
    error_log('GraphDB upload result: ' . $graphDbResult, 3, OMEKA_PATH . '/logs/auxNew.log');

    // log ttl data
    error_log('GraphDB upload result: ' . $graphDbResult, 3, OMEKA_PATH . '/logs/auxNew.log');
    
    if (strpos($graphDbResult, 'successfully') !== false) {
        // If GraphDB upload is successful, then process in Omeka S
        $omekaResult = $this->transformTtlToOmekaSData($ttlData, $itemSetId);
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
                return "Data uploaded successfully to both GraphDB and Omeka S. Created Item Set #{$itemSetId} for excavation 'EXC-{$itemSetId}' and " . 
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



/**
 * Process all properties of a subject
 */
private function processAllProperties($rdfData, $subject, &$itemData, $propertyMapping) {
    if (!isset($rdfData[$subject])) {
        return;
    }
    
    foreach ($rdfData[$subject] as $predicate => $objects) {
        if (isset($propertyMapping[$predicate])) {
            $mapping = $propertyMapping[$predicate];
            $term = $mapping['term'];
            $property_id = $mapping['property_id'];
            
            if (!isset($itemData[$term])) {
                $itemData[$term] = [];
            }
            
            foreach ($objects as $object) {
                $value = $this->createValueFromObject($object, $property_id, $predicate);
                if ($value !== null) {
                    $itemData[$term][] = $value;
                }
            }
        }
    }
}

/**
 * Recursively process all related subjects (morphology, chipping, coordinates, etc.)
 */
private function processAllRelatedSubjects($rdfData, $subject, &$itemData, $propertyMapping) {
    if (!isset($rdfData[$subject])) {
        return;
    }
    
    // Find all subjects related to this subject
    $relatedSubjects = [];
    foreach ($rdfData[$subject] as $predicate => $objects) {
        foreach ($objects as $object) {
            if ($object['type'] === 'uri' && isset($rdfData[$object['value']])) {
                $relatedSubjects[$object['value']] = $predicate;
            }
        }
    }
    
    // Process each related subject
    foreach ($relatedSubjects as $relatedSubject => $linkPredicate) {
        // Process direct properties of the related subject
        $this->processAllProperties($rdfData, $relatedSubject, $itemData, $propertyMapping);
        
        // Recursively process related subjects of the related subject
        $this->processAllRelatedSubjects($rdfData, $relatedSubject, $itemData, $propertyMapping);
    }
}

/**
 * Create a value array from an RDF object
 */
private function createValueFromObject($object, $property_id, $predicate) {
    if ($object['type'] === 'literal') {
        // Handle boolean values
        if ($object['value'] === 'true' || $object['value'] === 'false') {
            return [
                'type' => 'literal',
                'property_id' => $property_id,
                '@value' => $object['value'] === 'true' ? 'True' : 'False',
            ];
        } else {
            $value = [
                'type' => 'literal',
                'property_id' => $property_id,
                '@value' => $object['value'],
            ];
            if (isset($object['datatype'])) {
                $value['@type'] = $object['datatype'];
            }
            return $value;
        }
    } elseif ($object['type'] === 'uri') {
        // Extract the term from the URI for controlled vocabularies
        if (strpos($object['value'], '/kos/') !== false) {
            $parts = explode('/', $object['value']);
            $term = end($parts);
            return [
                'type' => 'literal',
                'property_id' => $property_id,
                '@value' => $term,
            ];
        } else {
            return [
                'type' => 'uri',
                'property_id' => $property_id,
                '@id' => $object['value'],
                '@value' => $object['value'],
            ];
        }
    }
    
    return null;
}
private function transformTtlToOmekaSData($ttlData, $itemSetId = null): array {
    error_log('Transforming TTL to Omeka S data', 3, OMEKA_PATH . '/logs/transform.log');
    $graph = new \EasyRdf\Graph();
    $graph->parse($ttlData, 'turtle');
    
    $omekaData = [];
    $excavationData = null;
    $rdfData = $graph->toRdfPhp();

    error_log('RDF Data: ' . print_r($rdfData, true), 3, OMEKA_PATH . '/logs/transform.log');
    
    // Find main subjects (arrowheads, excavation components, etc.)
    $subjects = [];
    $arrowheadSubjects = [];
    $excavationSubjects = [];
    $otherSubjects = [];
    
    // First pass: categorize all subjects
    foreach ($rdfData as $subject => $predicates) {
        foreach ($predicates as $predicate => $objects) {
            foreach ($objects as $object) {
                if ($predicate === 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type' && $object['type'] === 'uri') {
                    // Arrowhead
                    if ($object['value'] === 'https://purl.org/megalod/ms/ah/Arrowhead' || 
                        strpos($object['value'], 'Arrowhead') !== false ||
                        $object['value'] === 'http://www.cidoc-crm.org/cidoc-crm/E24_Physical_Man-Made_Thing') {
                        $arrowheadSubjects[$subject] = 'arrowhead';
                    }
                    // Excavation
                    else if ($object['value'] === 'http://www.cidoc-crm.org/extensions/crmarchaeo/A9_Archaeological_Excavation' || 
                             strpos($object['value'], 'Excavation') !== false) {
                        $excavationSubjects[$subject] = 'excavation';
                    }
                    // Context
                    else if (strpos($object['value'], 'Context') !== false) {
                        $otherSubjects[$subject] = 'context';
                    }
                    // SVU
                    else if (strpos($object['value'], 'StratigraphicVolumeUnit') !== false) {
                        $otherSubjects[$subject] = 'svu';
                    }
                    // Item
                    else if ($object['value'] === 'https://purl.org/megalod/ms/excavation/Item') {
                        $arrowheadSubjects[$subject] = 'item';
                    }
                }
            }
        }
    }
    
    // Second pass: decide which subjects to process based on upload context
    if ($itemSetId) {
        // We're uploading to an existing item set (likely adding arrowheads to an excavation)
        if (!empty($arrowheadSubjects)) {
            // This is an arrowhead upload - only process arrowheads
            $subjects = $arrowheadSubjects;
            error_log('Processing ' . count($subjects) . ' arrowhead subjects in context of item set ' . $itemSetId, 
                     3, OMEKA_PATH . '/logs/transform.log');
        } else {
            // This might be additional excavation data - process everything
            $subjects = array_merge($excavationSubjects, $otherSubjects);
            error_log('Processing ' . count($subjects) . ' non-arrowhead subjects in context of item set ' . $itemSetId, 
                     3, OMEKA_PATH . '/logs/transform.log');
        }
    } else {
        // New upload, no item set context - process everything
        $subjects = array_merge($arrowheadSubjects, $excavationSubjects, $otherSubjects);
        error_log('Processing all ' . count($subjects) . ' subjects without item set context', 
                 3, OMEKA_PATH . '/logs/transform.log');
    }
    
    // If no subjects found, look for any subject that has a dcterms:identifier
    if (empty($subjects)) {
        foreach ($rdfData as $subject => $predicates) {
            if (isset($predicates['http://purl.org/dc/terms/identifier'])) {
                $subjects[$subject] = 'unknown';
            }
        }
    }
    
    error_log('Final subjects to process: ' . print_r($subjects, true), 3, OMEKA_PATH . '/logs/transform.log');
    
    // Get excavation identifier for context - use either the default or from item set
    $excavationId = "0"; // Default
    if ($itemSetId) {
        $mappedId = $this->getExcavationIdentifierFromItemSet($itemSetId);
        error_log('Mapped ID: ' . $mappedId, 3, OMEKA_PATH . '/logs/mappingtoomeka.log');
        if ($mappedId) {
            $excavationId = $mappedId;
        }
    }
    
    // Process each main subject as a separate item
    foreach ($subjects as $subject => $subjectType) {
        $itemData = [
            'o:resource_class' => ['o:id' => 1], // Default Item Resource Class ID
            'o:item_set' => [],                  // Will be populated if itemSetId exists
        ];
        
        // Add item to item set if provided
        if ($itemSetId) {
            $itemData['o:item_set'][] = ['o:id' => $itemSetId];
        }
        
        // Extract basic properties - start with the identifier
        $identifier = $this->extractIdentifier($rdfData, $subject);
        if ($identifier) {
            $itemData['dcterms:identifier'] = [
                [
                    'type' => 'literal',
                    'property_id' => 10, // dcterms:identifier property ID
                    '@value' => $identifier
                ]
            ];
        }
        
        // Set a proper title
        $itemType = $this->determineItemType($subjectType);
        $title = $itemType;
        if ($identifier) {
            $title .= " " . $identifier;
            if ($excavationId != "0") {
                $title .= " (Excavation $excavationId)";
            }
        } else {
            // Extract subject ID as fallback
            $parts = explode('/', $subject);
            $lastPart = end($parts);
            $title .= " " . $lastPart;
        }
        
        $itemData['dcterms:title'] = [
            [
                'type' => 'literal',
                'property_id' => 1, // dcterms:title property ID
                '@value' => $title
            ]
        ];
        
        // Extract common properties
        $this->extractCommonProperties($rdfData, $subject, $itemData);
        
        // Handle specific subject types
        if ($subjectType == 'arrowhead' || $subjectType == 'item') {
            $this->processArrowheadData($rdfData, $subject, $itemData);
        } else if ($subjectType == 'excavation') {
            $this->processExcavationData($rdfData, $subject, $itemData);
        } else if ($subjectType == 'context') {
            $this->processContextData($rdfData, $subject, $itemData);
        } else if ($subjectType == 'svu') {
            $this->processSVUData($rdfData, $subject, $itemData);
        }
        
        // Add excavation context as spatial coverage
        if ($excavationId != "0") {
            if (!isset($itemData['dcterms:spatial'])) {
                $itemData['dcterms:spatial'] = [];
            }
            
            $itemData['dcterms:spatial'][] = [
                'type' => 'literal',
                'property_id' => 18, // spatial property ID 
                '@value' => "Excavation $excavationId"
            ];
        }
        
        $omekaData[] = $itemData;
    }

    foreach ($omekaData as $index => $itemData) {
        // Check if this item is an excavation
        if (isset($itemData['dcterms:title']) && 
            isset($itemData['dcterms:title'][0]['@value']) && 
            strpos($itemData['dcterms:title'][0]['@value'], 'Excavation') === 0) {
            
            // Extract excavation identifier
            $identifier = '';
            if (isset($itemData['Excavation ID']) && isset($itemData['Excavation ID'][0]['@value'])) {
                $identifier = $itemData['Excavation ID'][0]['@value'];
            } elseif (isset($itemData['dcterms:identifier']) && isset($itemData['dcterms:identifier'][0]['@value'])) {
                $identifier = $itemData['dcterms:identifier'][0]['@value'];
            }
            
            // Extract location name
            $location = '';
            if (isset($itemData['Location']) && isset($itemData['Location'][0]['@value'])) {
                $location = $itemData['Location'][0]['@value'];
            }
            
            // Extract archaeologist name
            $archaeologist = '';
            if (isset($itemData['Person in Charge']) && isset($itemData['Person in Charge'][0]['@value'])) {
                $archaeologist = $itemData['Person in Charge'][0]['@value'];
            }
            
            // Extract GPS coordinates
            $gps = '';
            if (isset($itemData['GPS Coordinates']) && isset($itemData['GPS Coordinates'][0]['@value'])) {
                $gps = $itemData['GPS Coordinates'][0]['@value'];
            }
            
            // Extract location details
            $locationDetails = '';
            if (isset($itemData['Location Details']) && isset($itemData['Location Details'][0]['@value'])) {
                $locationDetails = $itemData['Location Details'][0]['@value'];
            }
            
            // Store excavation data to be used later
            $excavationData = [
                'identifier' => $identifier,
                'location' => $location,
                'archaeologist' => $archaeologist,
                'gps' => $gps,
                'location_details' => $locationDetails
            ];
            
            // Log what we found
            error_log('Found excavation data: ' . print_r($excavationData, true), 3, OMEKA_PATH . '/logs/excavation-update.log');
            
            // No need to check other items
            break;
        }
    }

    $this->excavationData = $excavationData;


    error_log('Transformed TTL to Omeka S data: ' . print_r($omekaData, true), 3, OMEKA_PATH . '/logs/transform.log');
    
    return $omekaData;
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

/**
 * Extract common properties that apply to all subject types
 */
private function extractCommonProperties($rdfData, $subject, &$itemData) {
    // Map common predicates to Omeka S properties
    $commonPropertyMap = [
        'http://dbpedia.org/ontology/Annotation' => ['dcterms:description', 4],
        'http://www.cidoc-crm.org/cidoc-crm/E3_Condition_State' => ['crm:P44_has_condition', 476],
        'http://www.cidoc-crm.org/cidoc-crm/E55_Type' => ['crm:P2_has_type', 399]
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
                    // Handle boolean values
                    if ($object['value'] === 'true' || $object['value'] === 'false') {
                        $itemData[$term][] = [
                            'type' => 'literal',
                            'property_id' => $propertyId,
                            '@value' => $object['value'] === 'true' ? 'True' : 'False'
                        ];
                    } else {
                        $itemData[$term][] = [
                            'type' => 'literal',
                            'property_id' => $propertyId,
                            '@value' => $object['value']
                        ];
                    }
                } elseif ($object['type'] === 'uri') {
                    // Extract the term from the URI
                    $parts = explode('/', $object['value']);
                    $term = end($parts);
                    
                    $itemData[$term][] = [
                        'type' => 'literal',
                        'property_id' => $propertyId,
                        '@value' => $term
                    ];
                }
            }
        }
    }
}

/**
 * Process arrowhead specific data
 */
/**
 * Process arrowhead specific data
 */
private function processArrowheadData($rdfData, $subject, &$itemData) {
    // Basic properties - direct mapping
    error_log('Subject predicates: ' . print_r(array_keys($rdfData[$subject]), true), 3, OMEKA_PATH . '/logs/predicates.log');
    $propertyMap = [
        'https://purl.org/megalod/ms/ah/shape' => ['ah:shape', 7651],
        'https://purl.org/megalod/ms/ah/variant' => ['ah:variant', 7652],
        'http://www.cidoc-crm.org/cidoc-crm/P45_consists_of' => ['dcterms:medium', 13],
        'https://purl.org/megalod/ms/excavation/elongationIndex' => ['Elongation Index', 7676],
        'https://purl.org/megalod/ms/excavation/thicknessIndex' => ['Thickness Index', 7677]
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
                    // Extract the term from the URI
                    $parts = explode('/', $object['value']);
                    $value = end($parts);
                    
                    $itemData[$term][] = [
                        'type' => 'literal',
                        'property_id' => $propertyId,
                        '@value' => $value
                    ];
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

       
    // process encounter event data
    // In the processArrowheadData function, add this section to process encounter events:

// Extract encounter event data for this arrowhead
// Process encounter event data for this arrowhead
// Find all encounter events that reference this arrowhead
foreach ($rdfData as $encounterSubject => $encounterPredicates) {
    // Check if this is an encounter event
    $isEncounterEvent = false;
    if (isset($encounterPredicates['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'])) {
        foreach ($encounterPredicates['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'] as $typeObj) {
            if ($typeObj['type'] === 'uri' && 
                (strpos($typeObj['value'], 'EncounterEvent') !== false || 
                 $typeObj['value'] === 'https://purl.org/megalod/ms/excavation/EncounterEvent')) {
                $isEncounterEvent = true;
                break;
            }
        }
    }
    
    // If it's an encounter event, check if it's for this arrowhead
    if ($isEncounterEvent) {
        // Look for all possible predicates that might link an encounter event to an arrowhead
        $possiblePredicates = [
            'http://cidoc-crm.org/extensions/crmsci/O19_encountered_object',
            'crmsci:O19_encountered_object',
            'https://cidoc-crm.org/extensions/crmsci/O19_encountered_object'
        ];
        
        $isForThisArrowhead = false;
        foreach ($possiblePredicates as $predicate) {
            if (isset($encounterPredicates[$predicate])) {
                foreach ($encounterPredicates[$predicate] as $obj) {
                    if ($obj['type'] === 'uri' && $obj['value'] === $subject) {
                        $isForThisArrowhead = true;
                        break 2; // Break out of both loops
                    }
                }
            }
        }
        
        if ($isForThisArrowhead) {
            // Found an encounter event for this arrowhead
            error_log('Found encounter event for arrowhead: ' . $encounterSubject, 3, OMEKA_PATH . '/logs/encounter-events.log');
            
            // Process date information - check multiple potential predicates
            $datePredicates = [
                'http://purl.org/dc/terms/date',
                'dct:date',
                'https://purl.org/dc/terms/date'
            ];
            
            foreach ($datePredicates as $datePredicate) {
                if (isset($encounterPredicates[$datePredicate])) {
                    if (!isset($itemData['Encounter Date'])) {
                        $itemData['Encounter Date'] = [];
                    }
                    
                    foreach ($encounterPredicates[$datePredicate] as $dateObj) {
                        if ($dateObj['type'] === 'literal') {
                            $itemData['Encounter Date'][] = [
                                'type' => 'literal',
                                'property_id' => 7, // Use appropriate ID for date
                                '@value' => $dateObj['value']
                            ];
                            error_log('Added encounter date: ' . $dateObj['value'], 3, OMEKA_PATH . '/logs/encounter-events.log');
                        }
                    }
                }
            }
            
            // Process SVU information - check multiple potential predicates
            $svuPredicates = [
                'https://purl.org/megalod/ms/excavation/foundInSVU',
                'excav:foundInSVU'
            ];
            
            foreach ($svuPredicates as $svuPredicate) {
                if (isset($encounterPredicates[$svuPredicate])) {
                    if (!isset($itemData['Found in SVU'])) {
                        $itemData['Found in SVU'] = [];
                    }
                    
                    foreach ($encounterPredicates[$svuPredicate] as $svuObj) {
                        if ($svuObj['type'] === 'uri') {
                            $svuId = $this->extractResourceIdentifier($rdfData, $svuObj['value']);
                            $itemData['https://purl.org/megalod/ms/excavation/foundInSVU'][] = [
                                'type' => 'uri', // Changed from 'resource' to 'uri'
                                'property_id' => 7671,
                                '@id' => $svuObj['value'] // Use the full URI
                            ];
                            error_log('Added SVU reference as URI: ' . $svuObj['value'], 3, OMEKA_PATH . '/logs/encounter-events.log');
                        }
                    }
                }
            }
            
            // Process Context information - check multiple potential predicates
            $contextPredicates = [
                'https://purl.org/megalod/ms/excavation/foundInContext',
                'excav:foundInContext'
            ];
            
            foreach ($contextPredicates as $contextPredicate) {
                if (isset($encounterPredicates[$contextPredicate])) {
                    if (!isset($itemData['Found in Context'])) {
                        $itemData['Found in Context'] = [];
                    }
                    
                    foreach ($encounterPredicates[$contextPredicate] as $ctxObj) {
                        if ($ctxObj['type'] === 'uri') {
                            $ctxId = $this->extractResourceIdentifier($rdfData, $ctxObj['value']);
                            $itemData['https://purl.org/megalod/ms/excavation/foundInContext'][] = [
                                'type' => 'uri', // Changed from 'resource' to 'uri'
                                'property_id' => 7672,
                                '@id' => $ctxObj['value'] // Use the full URI
                            ];
                            error_log('Added Context reference as URI: ' . $ctxObj['value'], 3, OMEKA_PATH . '/logs/encounter-events.log');
                        }
                    }
                }
            }
            
            // Process Excavation information - check multiple potential predicates
            $excavationPredicates = [
                'https://purl.org/megalod/ms/excavation/foundInExcavation',
                'excav:foundInExcavation',
                'excav:foundInAExcavation'
            ];
            
            foreach ($excavationPredicates as $excavationPredicate) {
                if (isset($encounterPredicates[$excavationPredicate])) {
                    if (!isset($itemData['Found in Excavation'])) {
                        $itemData['Found in Excavation'] = [];
                    }
                    
                    foreach ($encounterPredicates[$excavationPredicate] as $excObj) {
                        if ($excObj['type'] === 'uri') {
                            $excId = $this->extractResourceIdentifier($rdfData, $excObj['value']);
                            $itemData['https://purl.org/megalod/ms/excavation/foundInExcavation'][] = [
                                'type' => 'uri', // Changed from 'resource' to 'uri'
                                'property_id' => 7673,
                                '@id' => $excObj['value'] // Use the full URI
                            ];
                            error_log('Added Excavation reference as URI: ' . $excObj['value'], 3, OMEKA_PATH . '/logs/encounter-events.log');
                        }
                    }
                }
            }
            
            // Process depth information if available
            $depthPredicates = [
                'http://dbpedia.org/ontology/depth',
                'dbo:depth'
            ];
            
            foreach ($depthPredicates as $depthPredicate) {
                if (isset($encounterPredicates[$depthPredicate])) {
                    if (!isset($itemData['Encounter Depth'])) {
                        $itemData['Encounter Depth'] = [];
                    }
                    
                    foreach ($encounterPredicates[$depthPredicate] as $depthObj) {
                        if ($depthObj['type'] === 'literal') {
                            $itemData['Encounter Depth'][] = [
                                'type' => 'literal',
                                'property_id' => 7675, // Use appropriate ID for depth
                                '@value' => $depthObj['value'] . (isset($depthObj['datatype']) && $depthObj['datatype'] === 'http://www.w3.org/2001/XMLSchema#decimal' ? ' m' : '')
                            ];
                            error_log('Added encounter depth: ' . $depthObj['value'], 3, OMEKA_PATH . '/logs/encounter-events.log');
                        }
                    }
                }
            }
        }
    }
}

    if (isset($rdfData[$subject]['https://purl.org/megalod/ms/excavation/hasCoordinatesInSquare'])) {
        foreach ($rdfData[$subject]['https://purl.org/megalod/ms/excavation/hasCoordinatesInSquare'] as $coordObj) {
            if ($coordObj['type'] === 'uri' && isset($rdfData[$coordObj['value']])) {
                $coordUri = $coordObj['value'];
                
                // Extract latitude, longitude and depth
                $lat = null;
                $long = null;
                $depth = null;
                
                if (isset($rdfData[$coordUri]['http://www.w3.org/2003/01/geo/wgs84_pos#latitude'])) {
                    foreach ($rdfData[$coordUri]['http://www.w3.org/2003/01/geo/wgs84_pos#latitude'] as $latObj) {
                        if ($latObj['type'] === 'literal') {
                            $lat = $latObj['value'];
                        }
                    }
                }
                
                if (isset($rdfData[$coordUri]['http://www.w3.org/2003/01/geo/wgs84_pos#longitude'])) {
                    foreach ($rdfData[$coordUri]['http://www.w3.org/2003/01/geo/wgs84_pos#longitude'] as $longObj) {
                        if ($longObj['type'] === 'literal') {
                            $long = $longObj['value'];
                        }
                    }
                }
                
                if (isset($rdfData[$coordUri]['http://schema.org/depth'])) {
                    foreach ($rdfData[$coordUri]['http://schema.org/depth'] as $depthObj) {
                        if ($depthObj['type'] === 'uri') {
                            $depthUri = $depthObj['value'];
                            $depth = $this->extractMeasurementValue($rdfData, $depthUri);
                        }
                    }
                }
                
                // ONLY add the combined coordinates representation
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
    
    // Extract morphology data
    if (isset($rdfData[$subject]['https://purl.org/megalod/ms/ah/hasMorphology'])) {
        foreach ($rdfData[$subject]['https://purl.org/megalod/ms/ah/hasMorphology'] as $morphObj) {
            if ($morphObj['type'] === 'uri' && isset($rdfData[$morphObj['value']])) {
                $morphUri = $morphObj['value'];
                
                // Extract point property
                if (isset($rdfData[$morphUri]['https://purl.org/megalod/ms/ah/point'])) {
                    foreach ($rdfData[$morphUri]['https://purl.org/megalod/ms/ah/point'] as $pointObj) {
                        if ($pointObj['type'] === 'literal') {
                            if (!isset($itemData['Point'])) {
                                $itemData['Point'] = [];
                            }
                            $itemData['Point'][] = [
                                'type' => 'literal',
                                'property_id' => 7653,
                                '@value' => $pointObj['value'] === 'true' ? 'True' : 'False'
                            ];
                        }
                    }
                }

                // Extract body property
                if (isset($rdfData[$morphUri]['https://purl.org/megalod/ms/ah/body'])) {
                    foreach ($rdfData[$morphUri]['https://purl.org/megalod/ms/ah/body'] as $bodyObj) {
                        if ($bodyObj['type'] === 'literal') {
                            if (!isset($itemData['Body'])) {
                                $itemData['Body'] = [];
                            }
                            $itemData['Body'][] = [
                                'type' => 'literal',
                                'property_id' => 7654,
                                '@value' => $bodyObj['value'] === 'true' ? 'True' : 'False'
                            ];
                        }
                    }
                }
                
                // Extract base property
                if (isset($rdfData[$morphUri]['https://purl.org/megalod/ms/ah/base'])) {
                    foreach ($rdfData[$morphUri]['https://purl.org/megalod/ms/ah/base'] as $baseObj) {
                        if ($baseObj['type'] === 'uri') {
                            if (!isset($itemData['ah:base'])) {
                                $itemData['ah:base'] = [];
                            }
                            $parts = explode('/', $baseObj['value']);
                            $value = end($parts);
                            $itemData['ah:base'][] = [
                                'type' => 'literal',
                                'property_id' => 7655,
                                '@value' => $value
                            ];
                        }
                    }
                }
            }
        }
    }
    
    // Extract chipping data
    if (isset($rdfData[$subject]['https://purl.org/megalod/ms/ah/hasChipping'])) {
        foreach ($rdfData[$subject]['https://purl.org/megalod/ms/ah/hasChipping'] as $chipObj) {
            if ($chipObj['type'] === 'uri' && isset($rdfData[$chipObj['value']])) {
                $chipUri = $chipObj['value'];
                
                // Map of chipping properties
                $chippingMap = [
                    'https://purl.org/megalod/ms/ah/chippingMode' => ['ah:chippingMode', 7656],
                    'https://purl.org/megalod/ms/ah/chippingAmplitude' => ['ah:chippingAmplitude', 7657],
                    'https://purl.org/megalod/ms/ah/chippingDirection' => ['ah:chippingDirection', 7658],
                    'https://purl.org/megalod/ms/ah/chippingOrientation' => ['ah:chippingOrientation', 7659],
                    'https://purl.org/megalod/ms/ah/chippingDelineation' => ['ah:chippingDelineation', 7660],
                    'https://purl.org/megalod/ms/ah/chippingShape' => ['ah:chippingShape', 7661],
                    'https://purl.org/megalod/ms/ah/chippingLocationSide' => ['ah:chippingLocationSide', 7662],
                    'https://purl.org/megalod/ms/ah/chippingLocationTransversal' => ['ah:chippingLocationTransversal', 7663]
                ];
                
                foreach ($chippingMap as $predicate => $mapping) {
                    $term = $mapping[0];
                    $propertyId = $mapping[1];
                    
                    if (isset($rdfData[$chipUri][$predicate])) {
                        if (!isset($itemData[$term])) {
                            $itemData[$term] = [];
                        }
                        
                        foreach ($rdfData[$chipUri][$predicate] as $obj) {
                            if ($obj['type'] === 'uri') {
                                $parts = explode('/', $obj['value']);
                                $value = end($parts);
                                $itemData[$term][] = [
                                    'type' => 'literal',
                                    'property_id' => $propertyId,
                                    '@value' => $value
                                ];
                            } else if ($obj['type'] === 'literal') {
                                $value = $obj['value'];
                                if ($value === 'true' || $value === 'false') {
                                    $value = $value === 'true' ? 'True' : 'False';
                                }
                                $itemData[$term][] = [
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
    
    // Extract typometry values (measurements)
    // Extract typometry values (measurements) - with combined value and unit
$typometryMap = [
    'https://purl.org/megalod/ms/ah/bodyLength' => ['ah:bodyLength', 7649],
    'https://purl.org/megalod/ms/ah/baseLength' => ['ah:baseLength', 7650],
    'http://schema.org/height' => ['height', 5616],    // Changed the property name to match display
    'http://schema.org/width' => ['width', 5688],      // Changed the property name to match display
    'http://schema.org/depth' => ['depth', 7244]       // Changed the property name to match display
];

foreach ($typometryMap as $predicate => $mapping) {
    if (isset($rdfData[$subject][$predicate])) {
        $term = $mapping[0];
        $propertyId = $mapping[1];
        
        foreach ($rdfData[$subject][$predicate] as $obj) {
            if ($obj['type'] === 'uri') {
                $typometryUri = $obj['value'];
                
                // Get the value and unit
                $value = $this->extractMeasurementValue($rdfData, $typometryUri);
                $unit = $this->extractMeasurementUnit($rdfData, $typometryUri);
                
                // Combine value and unit into a single property
                // Combine value and unit into a single property for all measurements
                if ($value) {
                    if (!isset($itemData[$term])) {
                        $itemData[$term] = [];
                    }
                    $itemData[$term][] = [
                        'type' => 'literal',
                        'property_id' => $propertyId,
                        '@value' => $value . ' ' . ($unit ?: '')
                    ];
                    
                    error_log('Added measurement: ' . $term . ' = ' . $value . ' ' . ($unit ?: ''), 3, OMEKA_PATH . '/logs/measurements.log');
                }
            }
        }
    }
}
    
        // Updated weight handling using a proper weight property ID
        if (isset($rdfData[$subject]['http://schema.org/weight'])) {
        foreach ($rdfData[$subject]['http://schema.org/weight'] as $obj) {
            if ($obj['type'] === 'uri') {
                $weightUri = $obj['value'];
                error_log('Processing weight: ' . $weightUri, 3, OMEKA_PATH . '/logs/measurements.log');
                
                // Get the value and unit
                $value = $this->extractMeasurementValue($rdfData, $weightUri);
                $unit = $this->extractMeasurementUnit($rdfData, $weightUri);
                
                // Use a clearer property name for weight
                if ($value) {
                    if (!isset($itemData['Weight'])) {
                        $itemData['Weight'] = [];
                    }
                    $itemData['Weight'][] = [
                        'type' => 'literal',
                        'property_id' => 7403,
                        '@value' => $value . ' ' . ($unit ?: 'g')
                    ];
                    
                    error_log('Added weight value with unit: ' . $value . ' ' . ($unit ?: 'g'), 3, OMEKA_PATH . '/logs/measurements.log');
                }
            }
        }
    }
    
    // Extract coordinates
    if (isset($rdfData[$subject]['https://purl.org/megalod/ms/excav/hasCoordinatesInSquare'])) {
        foreach ($rdfData[$subject]['https://purl.org/megalod/ms/excav/hasCoordinatesInSquare'] as $coordObj) {
            if ($coordObj['type'] === 'uri' && isset($rdfData[$coordObj['value']])) {
                $coordUri = $coordObj['value'];
                
                // Extract latitude and longitude
                $lat = null;
                $long = null;
                $depth = null;
                
                if (isset($rdfData[$coordUri]['http://www.w3.org/2003/01/geo/wgs84_pos#latitude'])) {
                    foreach ($rdfData[$coordUri]['http://www.w3.org/2003/01/geo/wgs84_pos#latitude'] as $latObj) {
                        if ($latObj['type'] === 'literal') {
                            $lat = $latObj['value'];
                        }
                    }
                }
                
                if (isset($rdfData[$coordUri]['http://www.w3.org/2003/01/geo/wgs84_pos#longitude'])) {
                    foreach ($rdfData[$coordUri]['http://www.w3.org/2003/01/geo/wgs84_pos#longitude'] as $longObj) {
                        if ($longObj['type'] === 'literal') {
                            $long = $longObj['value'];
                        }
                    }
                }
                
                // Extract depth if present
                if (isset($rdfData[$coordUri]['http://schema.org/depth'])) {
                    foreach ($rdfData[$coordUri]['http://schema.org/depth'] as $depthObj) {
                        if ($depthObj['type'] === 'uri') {
                            $depthUri = $depthObj['value'];
                            $depth = $this->extractMeasurementValue($rdfData, $depthUri);
                        }
                    }
                }
                
                // Add coordinates to spatial information
                if ($lat && $long) {
                    if (!isset($itemData['dcterms:spatial'])) {
                        $itemData['dcterms:spatial'] = [];
                    }
                    
                    $coordText = "Latitude: $lat, Longitude: $long";
                    if ($depth) {
                        $coordText .= ", Depth: $depth";
                    }
                    
                    $itemData['dcterms:spatial'][] = [
                        'type' => 'literal',
                        'property_id' => 18,
                        '@value' => $coordText
                    ];
                }
            }
        }
    }
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
 * Extract the measurement value from a typometry URI
 */
private function extractMeasurementValue($rdfData, $typometryUri) {
    // Add logging to help debug
    error_log('Extracting measurement value from: ' . $typometryUri, 3, OMEKA_PATH . '/logs/measurements.log');
    
    // Check what type this resource is
    if (isset($rdfData[$typometryUri]['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'])) {
        foreach ($rdfData[$typometryUri]['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'] as $typeObj) {
            error_log('Resource type: ' . $typeObj['value'], 3, OMEKA_PATH . '/logs/measurements.log');
        }
    }
    
    // Standard schema:value property for most measurements
    if (isset($rdfData[$typometryUri]['http://schema.org/value'])) {
        foreach ($rdfData[$typometryUri]['http://schema.org/value'] as $valueObj) {
            if ($valueObj['type'] === 'literal') {
                error_log('Found value: ' . $valueObj['value'], 3, OMEKA_PATH . '/logs/measurements.log');
                return $valueObj['value'];
            }
        }
    }
    
    // Backup - try to find any value-like property if schema:value isn't present
    $valueProperties = [
        'http://schema.org/value',
        'http://www.w3.org/1999/02/22-rdf-syntax-ns#value',
        'http://purl.org/dc/terms/extent'
    ];
    
    foreach ($valueProperties as $valueProp) {
        if (isset($rdfData[$typometryUri][$valueProp])) {
            foreach ($rdfData[$typometryUri][$valueProp] as $valueObj) {
                if ($valueObj['type'] === 'literal') {
                    error_log('Found alternate value via ' . $valueProp . ': ' . $valueObj['value'], 3, OMEKA_PATH . '/logs/measurements.log');
                    return $valueObj['value'];
                }
            }
        }
    }
    
    // If we can't find a value, log all properties
    error_log('All properties for ' . $typometryUri . ': ' . print_r(array_keys($rdfData[$typometryUri]), true), 3, OMEKA_PATH . '/logs/measurements.log');
    
    return null;
}
private function extractResourceIdentifier($rdfData, $resourceUri) {
    if (isset($rdfData[$resourceUri]['http://purl.org/dc/terms/identifier'])) {
        foreach ($rdfData[$resourceUri]['http://purl.org/dc/terms/identifier'] as $idObj) {
            if ($idObj['type'] === 'literal') {
                return $idObj['value'];
            }
        }
    }
    return null;
}
/**
 * Extract the measurement unit from a typometry URI
 */
private function extractMeasurementUnit($rdfData, $typometryUri) {
    // Add logging to help debug
    error_log('Extracting measurement unit from: ' . $typometryUri, 3, OMEKA_PATH . '/logs/measurements.log');
    
    // Check for schema:UnitCode (standard property)
    if (isset($rdfData[$typometryUri]['http://schema.org/UnitCode'])) {
        foreach ($rdfData[$typometryUri]['http://schema.org/UnitCode'] as $unitObj) {
            if ($unitObj['type'] === 'uri') {
                $parts = explode('/', $unitObj['value']);
                $unit = end($parts);
                error_log('Found unit: ' . $unit, 3, OMEKA_PATH . '/logs/measurements.log');
                return $unit;
            }
        }
    }
    
    // Try to find any unit-like property if schema:UnitCode isn't present
    $unitProperties = [
        'http://schema.org/UnitCode',
        'http://schema.org/unitCode',
        'http://purl.org/dc/terms/format',
        'http://qudt.org/schema/qudt#unit'
    ];
    
    foreach ($unitProperties as $unitProp) {
        if (isset($rdfData[$typometryUri][$unitProp])) {
            foreach ($rdfData[$typometryUri][$unitProp] as $unitObj) {
                if ($unitObj['type'] === 'uri') {
                    $parts = explode('/', $unitObj['value']);
                    $unit = end($parts);
                    error_log('Found alternate unit via ' . $unitProp . ': ' . $unit, 3, OMEKA_PATH . '/logs/measurements.log');
                    return $unit;
                }
            }
        }
    }
    
    // If we can't find a unit, check if there's a standard unit we can infer from the type
    if (isset($rdfData[$typometryUri]['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'])) {
        foreach ($rdfData[$typometryUri]['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'] as $typeObj) {
            if ($typeObj['value'] === 'https://purl.org/megalod/ms/excavation/Weight') {
                error_log('Inferred unit GM from Weight type', 3, OMEKA_PATH . '/logs/measurements.log');
                return 'GM'; // Default unit for Weight
            }
        }
    }
    
    return null;
}

/**
 * Process excavation specific data
 */
private function processExcavationData($rdfData, $subject, &$itemData) {
    // Basic properties - direct mapping
    $propertyMap = [
        'http://purl.org/dc/terms/identifier' => ['Excavation ID', 10],
        'https://purl.org/megalod/ms/excavation/hasPersonInCharge' => ['Person in Charge', 7665],
        'http://www.ontologydesignpatterns.org/ont/dul/DUL.owl#hasLocation' => ['Location', 7664],
        'https://purl.org/megalod/ms/excavation/hasSquare' => ['Squares', 7668],
        'https://purl.org/megalod/ms/excavation/hasContext' => ['Contexts', 7666]
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
                    // Extract meaningful info from related resources
                    if ($predicate === 'https://purl.org/megalod/ms/excavation/hasPersonInCharge') {
                        // Extract archaeologist name if available
                        $archaeologistUri = $object['value'];
                        $archaeologistName = $this->extractArchaeologistName($rdfData, $archaeologistUri);
                        
                        if ($archaeologistName) {
                            $itemData[$term][] = [
                                'type' => 'literal',
                                'property_id' => $propertyId,
                                '@value' => $archaeologistName
                            ];
                        } else {
                            // Fall back to URI ID if name not found
                            $parts = explode('/', $object['value']);
                            $value = end($parts);
                            
                            $itemData[$term][] = [
                                'type' => 'literal',
                                'property_id' => $propertyId,
                                '@value' => $value
                            ];
                        }
                    } else if ($predicate === 'http://www.ontologydesignpatterns.org/ont/dul/DUL.owl#hasLocation') {
                        // Extract location name if available
                        $locationUri = $object['value'];
                        $locationName = $this->extractLocationName($rdfData, $locationUri);
                        
                        if ($locationName) {
                            $itemData[$term][] = [
                                'type' => 'literal',
                                'property_id' => $propertyId,
                                '@value' => $locationName
                            ];
                        } else {
                            // Fall back to URI ID if name not found
                            $parts = explode('/', $object['value']);
                            $value = end($parts);
                            
                            $itemData[$term][] = [
                                'type' => 'literal',
                                'property_id' => $propertyId,
                                '@value' => $value
                            ];
                        }
                    } else {
                        // For other properties, extract the ID part from the URI
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
                
      
                
                // Modify this section in processExcavationData() method
                // District
                if (isset($rdfData[$locationUri]['http://dbpedia.org/ontology/district'])) {
                    foreach ($rdfData[$locationUri]['http://dbpedia.org/ontology/district'] as $distObj) {
                        if ($distObj['type'] === 'uri') {
                            $distUri = $distObj['value'];
                            if (isset($rdfData[$distUri])) {
                                $parts = explode('/', $distUri);
                                $districtName = end($parts);
                                
                                // Add district as a separate field
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
                if (isset($rdfData[$locationUri]['http://dbpedia.org/ontology/parish'])) {
                    foreach ($rdfData[$locationUri]['http://dbpedia.org/ontology/parish'] as $parishObj) {
                        if ($parishObj['type'] === 'uri') {
                            $parishUri = $parishObj['value'];
                            if (isset($rdfData[$parishUri])) {
                                $parts = explode('/', $parishUri);
                                $parishName = end($parts);
                                
                                // Add parish as a separate field
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
                if (isset($rdfData[$locationUri]['http://dbpedia.org/ontology/country'])) {
                    foreach ($rdfData[$locationUri]['http://dbpedia.org/ontology/country'] as $countryObj) {
                        if ($countryObj['type'] === 'uri') {
                            $countryUri = $countryObj['value'];
                            if (isset($rdfData[$countryUri])) {
                                $parts = explode('/', $countryUri);
                                $countryName = end($parts);
                                
                                // Add country as a separate field
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
}

/**
 * Extract archaeologist name from URI
 */
private function extractArchaeologistName($rdfData, $archaeologistUri) {
    if (isset($rdfData[$archaeologistUri]['http://xmlns.com/foaf/0.1/name'])) {
        foreach ($rdfData[$archaeologistUri]['http://xmlns.com/foaf/0.1/name'] as $nameObj) {
            if ($nameObj['type'] === 'literal') {
                return $nameObj['value'];
            }
        }
    }
    return null;
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
        'https://purl.org/megalod/ms/excavation/hasSVU' => ['Stratigraphic Units', 7667]
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
                    // For SVU references, extract the identifier if available
                    if ($predicate === 'https://purl.org/megalod/ms/excavation/hasSVU') {
                        $svuUri = $object['value'];
                        $svuId = $this->extractSVUIdentifier($rdfData, $svuUri);
                        
                        if ($svuId) {
                            $itemData[$term][] = [
                                'type' => 'literal',
                                'property_id' => $propertyId,
                                '@value' => $svuId
                            ];
                        } else {
                            // Fall back to URI ID if identifier not found
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
    
    // Extract SVU summaries for better context understanding
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
                'property_id' => 7, // Use appropriate property ID
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
                            if (isset($rdfData[$beginUri]['https://purl.org/megalod/ms/excavation/bcac'])) {
                                foreach ($rdfData[$beginUri]['https://purl.org/megalod/ms/excavation/bcac'] as $bcObj) {
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
                            if (isset($rdfData[$endUri]['https://purl.org/megalod/ms/excavation/bcac'])) {
                                foreach ($rdfData[$endUri]['https://purl.org/megalod/ms/excavation/bcac'] as $bcObj) {
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
                if (isset($rdfData[$beginUri]['https://purl.org/megalod/ms/excavation/bcac'])) {
                    foreach ($rdfData[$beginUri]['https://purl.org/megalod/ms/excavation/bcac'] as $bcObj) {
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
                if (isset($rdfData[$endUri]['https://purl.org/megalod/ms/excavation/bcac'])) {
                    foreach ($rdfData[$endUri]['https://purl.org/megalod/ms/excavation/bcac'] as $bcObj) {
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
 * Determine the item type label based on the subject type
 */
private function determineItemType($subjectType) {
    switch ($subjectType) {
        case 'arrowhead':
            return 'Arrowhead';
        case 'excavation':
            return 'Excavation';
        case 'context':
            return 'Context';
        case 'svu':
            return 'Stratigraphic Unit';
        case 'item':
            return 'Archaeological Item';
        default:
            return 'Archaeological Object';
    }
}


/**
 * Process a related subject (morphology, chipping, coordinates, etc.)
 */
private function processRelatedSubject($rdfData, $subject, &$itemData, $propertyMapping) {
    if (!isset($rdfData[$subject])) {
        return;
    }
    
    foreach ($rdfData[$subject] as $predicate => $objects) {
        // Check if this predicate is in our mapping
        if (isset($propertyMapping[$predicate])) {
            $mapping = $propertyMapping[$predicate];
            $term = $mapping['term'];
            $property_id = $mapping['property_id'];
            
            if (!isset($itemData[$term])) {
                $itemData[$term] = [];
            }
            
            foreach ($objects as $object) {
                $value = null;
                
                if ($object['type'] === 'literal') {
                    // Handle boolean values
                    if ($object['value'] === 'true' || $object['value'] === 'false') {
                        $value = [
                            'type' => 'literal',
                            'property_id' => $property_id,
                            '@value' => $object['value'] === 'true' ? 'True' : 'False',
                        ];
                    } else {
                        $value = [
                            'type' => 'literal',
                            'property_id' => $property_id,
                            '@value' => $object['value'],
                        ];
                        if (isset($object['datatype'])) {
                            $value['@type'] = $object['datatype'];
                        }
                    }
                } elseif ($object['type'] === 'uri') {
                    // Extract the term from the URI for controlled vocabularies
                    if (strpos($object['value'], '/kos/') !== false) {
                        $parts = explode('/', $object['value']);
                        $term = end($parts);
                        $value = [
                            'type' => 'literal',
                            'property_id' => $property_id,
                            '@value' => $term,
                        ];
                    } else {
                        $value = [
                            'type' => 'uri',
                            'property_id' => $property_id,
                            '@id' => $object['value'],
                            '@value' => $object['value'],
                        ];
                    }
                }
                
                if ($value !== null) {
                    $itemData[$term][] = $value;
                }
            }
        }
        
        // Process nested relations
        foreach ($objects as $object) {
            if ($object['type'] === 'uri' && isset($rdfData[$object['value']])) {
                $this->processRelatedSubject($rdfData, $object['value'], $itemData, $propertyMapping);
            }
        }
    }
}


    // TO CHANGE
    private function processSubjectProperties($rdfData, $subject, &$itemData) {
        error_log('Processing subject properties for: ' . $subject, 3, OMEKA_PATH . '/logs/transform.log');
        if (!isset($rdfData[$subject])) {
            return;
        }
        
        foreach ($rdfData[$subject] as $predicate => $objects) {
            $propertyId = $this->getOmekaPropertyId($predicate);
            error_log('Processing predicate: ' . $predicate, 3, OMEKA_PATH . '/logs/extract.log');
            error_log('Property ID: ' . $propertyId, 3, OMEKA_PATH . '/logs/extract.log');
            
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
                        error_log('Literal value: ' . $object['value'], 3, OMEKA_PATH . '/logs/extract.log');
                        error_log('Property ID: ' . $propertyId, 3, OMEKA_PATH . '/logs/extract.log');
                        error_log('Predicate: ' . $predicate, 3, OMEKA_PATH . '/logs/extract.log');
                        error_log('Object: ' . print_r($object, true), 3, OMEKA_PATH . '/logs/extract.log');

                    } elseif ($object['type'] === 'uri') {
                        // Don't include references to other subjects we'll process separately
                        if (isset($rdfData[$object['value']])) {
                            continue;
                        }
                        
                        // Handle special cases for vocabulary terms
                        if (strpos($object['value'], 'https://purl.org/megalod/kos/') === 0) {
                            // Extract the term from the URI
                            $parts = explode('/', $object['value']);
                            $term = end($parts);
                            error_log('Term: ' . $term, 3, OMEKA_PATH . '/logs/extract.log');
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
                            error_log('Resource ID: ' . $object['value'], 3, OMEKA_PATH . '/logs/extract.log');
                            error_log('Property ID: ' . $propertyId, 3, OMEKA_PATH . '/logs/extract.log');
                            error_log('Predicate: ' . $predicate, 3, OMEKA_PATH . '/logs/extract.log');
                            error_log('Object: ' . print_r($object, true), 3, OMEKA_PATH . '/logs/extract.log');
                        }
                    }
                    
                    if ($value !== null) {
                        $itemData[$predicate][] = $value;
                    }
                }
            }
        }
        error_log('Processed properties for: ' . $subject, 3, OMEKA_PATH . '/logs/transform.log');
    }
    


    // TO CHANGE
    private function getOmekaPropertyId($omekaProperty) {
        $propertyIds = [
            'http://purl.org/dc/terms/identifier' => 10,  // dcterms:identifier
            'http://www.europeana.eu/schemas/edm#Webresource' => 100,  
            'https://purl.org/megalod/kos/ah-shape' => 7651,  
            'http://www.cidoc-crm.org/cidoc-crm/P45_consists_of' => 478,  
            'http://dbpedia.org/ontology/Annotation' => 57,  
            'http://www.cidoc-crm.org/cidoc-crm/E3_Condition_State' => 476,  
            'http://www.cidoc-crm.org/cidoc-crm/E55_Type' => 399,   
            'https://purl.org/megalod/kos/ah-variant' => 7652,  
            //'http://www.purl.com/ah/ms/ahMS#foundInCoordinates' => 7456,  
            //'http://www.purl.com/ah/ms/ahMS#hasMorphology' => 7463,  
            //'http://www.purl.com/ah/ms/ahMS#hasTypometry' => 7458,  
            //'http://www.purl.com/ah/ms/ahMS#point' => 7653,  CHECK THIS LATER
            // 'http://www.purl.com/ah/ms/ahMS#body' => 7654,  CHECK THIS LATER
            'https://purl.org/megalod/kos/ah-base' => 7655,  
            'http://www.cidoc-crm.org/cidoc-crm/E54_Dimension' => 474,  
            //'http://www.purl.com/ah/ms/ahMS#hasChipping' => 7459,  
            'https://purl.org/megalod/kos/ah-chippingMode' => 7656,  
            // 'http://www.purl.com/ah/ms/ahMS#amplitude' => 7657,  CHECK THIS LATER
            'https://purl.org/megalod/kos/ah-chippingDirection' => 7658,  
            //'http://www.purl.com/ah/ms/ahMS#orientation' => 7659,  CHECK THIS LATER
            'https://purl.org/megalod/kos/ah-chippingDelineation' => 7660,  
            'https://purl.org/megalod/kos/ah-chippingLocation-lateral' => 7662,  //CHECK THIS LATER
            'https://purl.org/megalod/kos/ah-chippingLocation-transversal' => 7663,  //CHECK THIS LATER
            'https://purl.org/megalod/kos/ah-chippingShape' => 7661,  
            'https://purl.org/megalod/kos/MegaLOD-BCAC' => 7644,
            'https://purl.org/megalod/kos/MegaLOD-IndexElongation' =>7645,
            'https://purl.org/megalod/kos/MegaLOD-IndexThickness' => 7646,
            'http://www.w3.org/2003/01/geo/wgs84_pos#lat' => 257,  
            'http://www.w3.org/2003/01/geo/wgs84_pos#long' => 259, 
        ];
        
        return $propertyIds[$omekaProperty] ?? null;
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
                $createdItems[] = json_decode($response->getBody(), true);
                error_log('Omeka S Item Created Successfully: ID=' . 
                           json_decode($response->getBody(), true)['o:id']);
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
}