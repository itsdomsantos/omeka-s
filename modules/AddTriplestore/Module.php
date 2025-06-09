<?php

namespace AddTriplestore;

use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\Http\Client;
use Omeka\Module\AbstractModule;

class Module extends AbstractModule
{
    /**
     * Get module configuration
     *
     * @return array Configuration array
     */
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    /**
     * Register event listeners during bootstrap
     *
     * @param MvcEvent $event
     */
    public function onBootstrap(MvcEvent $event)
    {
        parent::onBootstrap($event);
        $this->attachListeners($event->getApplication()->getServiceManager()->get('SharedEventManager'));
        
        // Add ACL rules for public access
        $services = $event->getApplication()->getServiceManager();
        $acl = $services->get('Omeka\Acl');
        
        // Allow anyone (including guests) to access site actions
        $acl->allow(
            null,
            ['AddTriplestore\Controller\Site\Index'],
            ['index', 'search', 'viewDetails', 'processCollectingForm', 'downloadTtl', 'aboutUs', 'upload']
        );
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        // Existing event listeners
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\ItemAdapter',
            'api.delete.pre',
            [$this, 'handleItemPreDeletion']
        );
        
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\ItemAdapter',
            'api.delete.post',
            [$this, 'handleItemDeletion']
        );
        
        // Add these event listeners to track item-itemset relationships
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\ItemAdapter',
            'api.create.post',
            [$this, 'trackItemItemSetRelationship']
        );
        
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\ItemAdapter',
            'api.update.post',
            [$this, 'trackItemItemSetRelationship']
        );
    }



    /**
     * Capture item data BEFORE deletion
     */
    public function handleItemPreDeletion($event)
    {
        $request = $event->getParam('request');
        $itemId = $request->getId();
        
        error_log("Item PRE-deletion detected: ID=$itemId", 3, OMEKA_PATH . '/logs/finalDelete.log');
        
        try {
            // Get the API manager
            $api = $this->getServiceLocator()->get('Omeka\ApiManager');
            
            // Read the item data
            $item = $api->read('items', $itemId)->getContent();
            
            // Get the settings manager
            $settings = $this->getServiceLocator()->get('Omeka\Settings');
            $itemDeletionInfo = $settings->get('addtriplestore_item_deletion_info', []);
            
            // Get item set info
            $itemSets = $item->itemSets();
            $itemSetIds = [];
            
            foreach ($itemSets as $itemSet) {
                $itemSetId = $itemSet->id();
                $itemSetIds[] = $itemSetId;
                error_log("Item $itemId belongs to item set $itemSetId ({$itemSet->title()})", 3, OMEKA_PATH . '/logs/finalDelete.log');
            }
            
            // Get identifier
            $identifier = null;
            $identifierValue = $item->value('dcterms:identifier', ['default' => null]);
            if ($identifierValue) {
                $identifier = (string) $identifierValue;
                error_log("Item identifier: $identifier", 3, OMEKA_PATH . '/logs/finalDelete.log');
            }
            
            // Store the deletion info using the item ID as key
            $itemDeletionInfo[$itemId] = [
                'itemSetIds' => $itemSetIds,
                'identifier' => $identifier,
                'timestamp' => time() // Add timestamp for potential cleanup
            ];
            
            // Save the updated deletion info
            $settings->set('addtriplestore_item_deletion_info', $itemDeletionInfo);
            error_log("Stored pre-deletion info in module settings for item $itemId", 3, OMEKA_PATH . '/logs/finalDelete.log');
            
        } catch (\Exception $e) {
            error_log("Error capturing pre-deletion data: " . $e->getMessage(), 3, OMEKA_PATH . '/logs/finalDelete.log');
        }
    }

    /**
     * Cache item deletion information for use in the post-delete handler
     */
    private function cacheItemDeletionInfo($itemId, $info)
    {
        // Use temporary file storage for simplicity
        $cacheDir = OMEKA_PATH . '/files/temp';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        
        $cacheFile = $cacheDir . "/item_deletion_$itemId.json";
        file_put_contents($cacheFile, json_encode($info));
        error_log("Cached pre-deletion info to $cacheFile", 3, OMEKA_PATH . '/logs/finalDelete.log');
    }

    /**
     * Get cached item deletion information
     */
    private function getCachedItemDeletionInfo($itemId)
    {
        // Fix the path to match exactly how it's stored
        $cacheDir = OMEKA_PATH . '/files/temp';
        $cacheFile = $cacheDir . "/item_deletion_$itemId.json";
        
        error_log("Looking for cached file: $cacheFile", 3, OMEKA_PATH . '/logs/finalDelete.log');
        
        if (file_exists($cacheFile)) {
            $content = file_get_contents($cacheFile);
            $info = json_decode($content, true);
            error_log("Retrieved cached deletion info for item $itemId: " . print_r($info, true), 3, OMEKA_PATH . '/logs/finalDelete.log');
            
            // Clean up the cache file
            unlink($cacheFile);
            
            return $info;
        }
        
        error_log("No cached deletion info found for item $itemId", 3, OMEKA_PATH . '/logs/finalDelete.log');
        return null;
    }

    /**
     * Handle item deletion post-event
     */
    private $processedDeletions = [];

    /**
     * Handle item deletion post-event
     */
    public function handleItemDeletion($event)
    {
        $request = $event->getParam('request');
        if (!$request) {
            error_log("No request in event parameters", 3, OMEKA_PATH . '/logs/finalDelete.log');
            return;
        }

        $itemId = $request->getId();
        error_log("=== ENHANCED ITEM DELETION PROCESSING ===", 3, OMEKA_PATH . '/logs/finalDelete.log');
        error_log("Processing deletion for item ID: $itemId", 3, OMEKA_PATH . '/logs/finalDelete.log');
        
        if (!$itemId) {
            error_log("Failed to retrieve item ID", 3, OMEKA_PATH . '/logs/finalDelete.log');
            return;
        }
        
        // Get session storage to prevent duplicate processing
        $session = new \Laminas\Session\Container('AddTriplestore');
        
        if (isset($session->processedDeletions) && in_array($itemId, $session->processedDeletions)) {
            error_log("Skipping duplicate deletion event for item $itemId", 3, OMEKA_PATH . '/logs/finalDelete.log');
            return;
        }
        
        if (!isset($session->processedDeletions)) {
            $session->processedDeletions = [];
        }
        
        $session->processedDeletions[] = $itemId;
        
        // Get the settings manager
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        
        // Strategy 1: Get pre-deletion info (most reliable)
        $itemDeletionInfo = $settings->get('addtriplestore_item_deletion_info', []);
        
        $identifier = null;
        $graphId = "0"; // Default graph
        
        if (isset($itemDeletionInfo[$itemId])) {
            $info = $itemDeletionInfo[$itemId];
            $identifier = $info['identifier'] ?? null;
            $itemSetIds = $info['itemSetIds'] ?? [];
            
            if (!empty($itemSetIds)) {
                $graphId = $itemSetIds[0]; // Use the first item set as the graph ID
                error_log("✓ Using pre-deletion item set ID as graph ID: $graphId", 3, OMEKA_PATH . '/logs/finalDelete.log');
            }
            
            // Clean up the pre-deletion info
            unset($itemDeletionInfo[$itemId]);
            $settings->set('addtriplestore_item_deletion_info', $itemDeletionInfo);
        } else {
            // Strategy 2: Use stored item-itemset mapping
            $itemItemSetMap = $settings->get('addtriplestore_item_itemset_map', []);
            
            if (isset($itemItemSetMap[$itemId])) {
                $graphId = $itemItemSetMap[$itemId];
                error_log("✓ Using mapped item set ID as graph ID: $graphId", 3, OMEKA_PATH . '/logs/finalDelete.log');
                
                // Remove this item from the mapping
                unset($itemItemSetMap[$itemId]);
                $settings->set('addtriplestore_item_itemset_map', $itemItemSetMap);
            } else {
                error_log("⚠ No mapping found for item $itemId, using default graph", 3, OMEKA_PATH . '/logs/finalDelete.log');
            }
        }
        
        // If we still don't have an identifier, try to generate a reasonable one
        if (!$identifier) {
            // Generate a fallback identifier based on the item ID
            $identifier = "ITEM-$itemId";
            error_log("⚠ Generated fallback identifier: $identifier", 3, OMEKA_PATH . '/logs/finalDelete.log');
        }
        
        error_log("Final deletion parameters: identifier='$identifier', itemId=$itemId, graphId='$graphId'", 3, OMEKA_PATH . '/logs/finalDelete.log');
        
        // Delete from GraphDB
        $this->deleteFromGraphDB($identifier, $itemId, $graphId);
        
        // Cleanup old processed deletions
        $this->cleanupProcessedDeletions($session);
    }

    /**
     * Debug method to see what's actually in the graph
     */
    private function debugGraphContents($graphUri, $identifier) {
        error_log("=== DEBUGGING GRAPH CONTENTS ===", 3, OMEKA_PATH . '/logs/finalDelete.log');
        
        $query = "
PREFIX dct: <http://purl.org/dc/terms/>
PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>

SELECT ?s ?p ?o
WHERE {
  GRAPH <$graphUri> {
    {
      # Find all triples related to our identifier
      ?artifact dct:identifier \"$identifier\"^^xsd:literal .
      ?artifact ?p ?o .
      BIND(?artifact AS ?s)
    }
    UNION
    {
      # Find anything that references our artifact
      ?s ?p ?artifact .
      ?artifact dct:identifier \"$identifier\"^^xsd:literal .
      BIND(?artifact AS ?o)
    }
    UNION
    {
      # Find anything with our identifier in the URI
      ?s ?p ?o .
      FILTER(CONTAINS(STR(?s), \"$identifier\"))
    }
  }
}
LIMIT 50";

        try {
            $response = $this->executeSparqlQuery("http://localhost:7200/repositories/megalod/statements", $query);
            $results = json_decode($response->getBody(), true);
            
            if (isset($results['results']['bindings'])) {
                $count = count($results['results']['bindings']);
                error_log("Found $count triples related to '$identifier' in graph $graphUri:", 3, OMEKA_PATH . '/logs/finalDelete.log');
                
                foreach ($results['results']['bindings'] as $binding) {
                    $s = $binding['s']['value'] ?? 'N/A';
                    $p = $binding['p']['value'] ?? 'N/A';
                    $o = $binding['o']['value'] ?? 'N/A';
                    error_log("  Triple: <$s> <$p> <$o>", 3, OMEKA_PATH . '/logs/finalDelete.log');
                }
            } else {
                error_log("No triples found for identifier '$identifier' in graph $graphUri", 3, OMEKA_PATH . '/logs/finalDelete.log');
            }
        } catch (\Exception $e) {
            error_log("Error debugging graph contents: " . $e->getMessage(), 3, OMEKA_PATH . '/logs/finalDelete.log');
        }
    }

    /**
     * Clean up old processed deletions from the session
     * to avoid session bloat over time
     */
    private function cleanupProcessedDeletions($session)
    {
        // Keep only the most recent 100 processed deletions
        if (isset($session->processedDeletions) && count($session->processedDeletions) > 100) {
            $session->processedDeletions = array_slice($session->processedDeletions, -100);
        }
    }


    /**
     * Clean up old deletion info (call this periodically or from the main module class)
     */
    private function cleanupOldDeletionInfo() 
    {
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $itemDeletionInfo = $settings->get('addtriplestore_item_deletion_info', []);
        
        if (empty($itemDeletionInfo)) {
            return;
        }
        
        $currentTime = time();
        $oneDayAgo = $currentTime - (24 * 60 * 60); // 24 hours in seconds
        
        $modified = false;
        foreach ($itemDeletionInfo as $itemId => $info) {
            if (isset($info['timestamp']) && $info['timestamp'] < $oneDayAgo) {
                unset($itemDeletionInfo[$itemId]);
                $modified = true;
            }
        }
        
        if ($modified) {
            $settings->set('addtriplestore_item_deletion_info', $itemDeletionInfo);
        }
    }

    public function install(ServiceLocatorInterface $serviceLocator)
    {
        $settings = $serviceLocator->get('Omeka\Settings');
        $settings->set('addtriplestore_item_itemset_map', []);
        $settings->set('addtriplestore_item_deletion_info', []);
    }
        

    private function deleteFromGraphDB($identifier, $itemId, $graphId)
    {
        $graphdbEndpoint = "http://localhost:7200/repositories/megalod/statements";
        $baseDataGraphUri = "https://purl.org/megalod/";
        $graphUri = $baseDataGraphUri . $graphId . "/";
        
        error_log("=== ENHANCED GRAPHDB DELETION ===", 3, OMEKA_PATH . '/logs/finalDelete.log');
        error_log("Deleting from GraphDB: identifier='$identifier', itemId=$itemId, graphUri='$graphUri'", 3, OMEKA_PATH . '/logs/finalDelete.log');
        
        try {
            // First, debug what's actually in the graph
            $this->debugGraphContents($graphUri, $identifier);
            
            // Count triples before deletion
            $countQuery = $this->buildCountQuery($graphUri, $identifier);
            $countResponse = $this->executeSparqlQuery($this->graphdbQueryEndpoint, $countQuery);
            
            $countData = json_decode($countResponse->getBody(), true);
            $tripleCount = 0;
            
            if ($countData && isset($countData['results']['bindings']) && 
                !empty($countData['results']['bindings'])) {
                $tripleCount = (int)$countData['results']['bindings'][0]['count']['value'];
                error_log("Found $tripleCount triples to delete for identifier '$identifier'", 3, OMEKA_PATH . '/logs/finalDelete.log');
            }
            
            if ($tripleCount == 0) {
                error_log("⚠ WARNING: No triples found to delete for identifier '$identifier' in graph '$graphUri'", 3, OMEKA_PATH . '/logs/finalDelete.log');
                
                // Try different graph URIs as fallback
                $fallbackGraphs = [
                    $baseDataGraphUri . "0/",  // Default graph
                    $baseDataGraphUri . $itemId . "/",  // Item ID as graph
                ];
                
                foreach ($fallbackGraphs as $fallbackGraph) {
                    if ($fallbackGraph === $graphUri) continue; // Skip if same as original
                    
                    error_log("Trying fallback graph: $fallbackGraph", 3, OMEKA_PATH . '/logs/finalDelete.log');
                    $this->debugGraphContents($fallbackGraph, $identifier);
                    
                    $fallbackCountQuery = $this->buildCountQuery($fallbackGraph, $identifier);
                    $fallbackCountResponse = $this->executeSparqlQuery($this->graphdbQueryEndpoint, $fallbackCountQuery);
                    $fallbackCountData = json_decode($fallbackCountResponse->getBody(), true);
                    
                    if ($fallbackCountData && isset($fallbackCountData['results']['bindings']) && 
                        !empty($fallbackCountData['results']['bindings'])) {
                        $fallbackCount = (int)$fallbackCountData['results']['bindings'][0]['count']['value'];
                        if ($fallbackCount > 0) {
                            error_log("✓ Found $fallbackCount triples in fallback graph $fallbackGraph", 3, OMEKA_PATH . '/logs/finalDelete.log');
                            $graphUri = $fallbackGraph;
                            $tripleCount = $fallbackCount;
                            break;
                        }
                    }
                }
            }
            
            // Proceed with deletion if we found triples
            if ($tripleCount > 0) {
                error_log("Proceeding with deletion of $tripleCount triples from $graphUri", 3, OMEKA_PATH . '/logs/finalDelete.log');
                
                $result = $this->deleteResourceByIdentifier($graphdbEndpoint, $graphUri, $identifier, $graphId);
                
                if ($result->isSuccess()) {
                    error_log("✓ SUCCESS: Deleted $tripleCount triples for identifier '$identifier'", 3, OMEKA_PATH . '/logs/finalDelete.log');
                    
                    // Verify deletion worked
                    $verifyCountResponse = $this->executeSparqlQuery("http://localhost:7200/repositories/megalod", $countQuery);
                    $verifyCountData = json_decode($verifyCountResponse->getBody(), true);
                    if ($verifyCountData && isset($verifyCountData['results']['bindings']) && 
                        !empty($verifyCountData['results']['bindings'])) {
                        $remainingCount = (int)$verifyCountData['results']['bindings'][0]['count']['value'];
                        error_log("Verification: $remainingCount triples remaining after deletion", 3, OMEKA_PATH . '/logs/finalDelete.log');
                    }
                } else {
                    error_log("❌ FAILED: Deletion query failed with status: " . $result->getStatusCode(), 3, OMEKA_PATH . '/logs/finalDelete.log');
                    error_log("Response body: " . $result->getBody(), 3, OMEKA_PATH . '/logs/finalDelete.log');
                }
            } else {
                error_log("❌ FAILED: No triples found to delete for identifier '$identifier' in any graph", 3, OMEKA_PATH . '/logs/finalDelete.log');
            }
            
        } catch (\Exception $e) {
            error_log("❌ ERROR: Exception during GraphDB deletion: " . $e->getMessage(), 3, OMEKA_PATH . '/logs/finalDelete.log');
        }
    }

    /**
     * Build a query to count triples associated with a particular identifier
     */
    private function buildCountQuery($graphUri, $identifier) {
        return "
PREFIX dct: <http://purl.org/dc/terms/>
PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>

SELECT (COUNT(*) as ?count)
WHERE {
  GRAPH <$graphUri> {
    {
      # Count all triples where our artifact is the subject
      ?artifact dct:identifier \"$identifier\"^^xsd:literal .
      ?artifact ?p1 ?o1 .
    }
    UNION
    {
      # Count all triples where our artifact is the object
      ?s2 ?p2 ?artifact .
      ?artifact dct:identifier \"$identifier\"^^xsd:literal .
    }
    UNION
    { 
      # Count all triples from paths with our identifier
      ?s3 ?p3 ?o3 .
      FILTER(CONTAINS(STR(?s3), \"/$identifier\") || CONTAINS(STR(?o3), \"/$identifier\"))
    }
  }
}";
    }

    /**
     * Execute a SPARQL SELECT query against GraphDB
     */
    private function executeSparqlQuery($endpoint, $query) {
        $client = new Client();
        $client->setMethod('POST');
        $client->setUri(str_replace('/statements', '', $endpoint));
        $client->setHeaders([
            'Content-Type' => 'application/sparql-query',
            'Accept' => 'application/json'
        ]);
        $client->setRawBody($query);
        
        try {
            $response = $client->send();
            return $response;
        } catch (\Exception $e) {
            error_log("Exception when executing SPARQL query: " . $e->getMessage(), 3, OMEKA_PATH . '/logs/finalDelete.log');
            throw $e;
        }
    }

    private function deleteResourceByIdentifier($endpoint, $graphUri, $identifier, $graphId)
    {
        error_log("=== ENHANCED DELETE WITH COMPREHENSIVE PATTERNS ===", 3, OMEKA_PATH . '/logs/finalDelete.log');
        error_log("Deleting identifier '$identifier' from graph $graphUri", 3, OMEKA_PATH . '/logs/finalDelete.log');
        
        $query = "
PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>
PREFIX dbo: <http://dbpedia.org/ontology/>
PREFIX dct: <http://purl.org/dc/terms/>
PREFIX foaf: <http://xmlns.com/foaf/0.1/>
PREFIX crm: <http://www.cidoc-crm.org/cidoc-crm/>
PREFIX crmsci: <http://cidoc-crm.org/extensions/crmsci/>
PREFIX edm: <http://www.europeana.eu/schemas/edm/>
PREFIX geo: <http://www.w3.org/2003/01/geo/wgs84_pos#>
PREFIX schema: <http://schema.org/>
PREFIX ah: <https://purl.org/megalod/ms/ah/>
PREFIX excav: <https://purl.org/megalod/ms/excavation/>
PREFIX wgs: <http://www.w3.org/2003/01/geo/wgs84_pos#>

DELETE {
  GRAPH <$graphUri> {
    ?s ?p ?o .
  }
}
WHERE {
  GRAPH <$graphUri> {
    {
      # Pattern 1: Main item with exact identifier match
      ?artifact dct:identifier \"$identifier\"^^xsd:literal .
      ?artifact ?p ?o .
      BIND(?artifact AS ?s)
    }
    UNION
    {
      # Pattern 2: All triples where the artifact is the object
      ?s ?p ?artifact .
      ?artifact dct:identifier \"$identifier\"^^xsd:literal .
    }
    UNION
    {
      # Pattern 3: Direct URI match for main item (handles both with and without trailing content)
      ?s ?p ?o .
      FILTER(
        (STR(?s) = \"$graphUri$identifier\") ||
        (STRSTARTS(STR(?s), \"$graphUri$identifier/\"))
      )
    }
    UNION
    {
      # Pattern 4: All triples where these URI-based resources are the object
      ?subject ?predicate ?s .
      FILTER(
        (STR(?s) = \"$graphUri$identifier\") ||
        (STRSTARTS(STR(?s), \"$graphUri$identifier/\"))
      )
    }
    UNION
    {
      # Pattern 5: Encounter events that reference this artifact
      ?encounter crmsci:O19_encountered_object ?artifact .
      ?artifact dct:identifier \"$identifier\"^^xsd:literal .
      ?encounter ?p ?o .
      BIND(?encounter AS ?s)
    }
    UNION
    {
      # Pattern 6: All triples where the encounter is the object
      ?s ?p ?encounter .
      ?encounter crmsci:O19_encountered_object ?artifact .
      ?artifact dct:identifier \"$identifier\"^^xsd:literal .
    }
    UNION
    {
      # Pattern 7: Related components via direct property relationships
      ?artifact dct:identifier \"$identifier\"^^xsd:literal .
      ?artifact ?relPred ?component .
      ?component ?p ?o .
      FILTER(
        STRSTARTS(STR(?relPred), \"http://schema.org/\") ||
        STRSTARTS(STR(?relPred), \"https://purl.org/megalod/ms/ah/\") ||
        STRSTARTS(STR(?relPred), \"https://purl.org/megalod/ms/excavation/\")
      )
      BIND(?component AS ?s)
    }
    UNION
    {
      # Pattern 8: All triples where related components are the object
      ?artifact dct:identifier \"$identifier\"^^xsd:literal .
      ?artifact ?relPred ?component .
      ?s ?p ?component .
      FILTER(
        STRSTARTS(STR(?relPred), \"http://schema.org/\") ||
        STRSTARTS(STR(?relPred), \"https://purl.org/megalod/ms/ah/\") ||
        STRSTARTS(STR(?relPred), \"https://purl.org/megalod/ms/excavation/\")
      )
    }
  }
}";

        error_log("ENHANCED SPARQL Delete Query: $query", 3, OMEKA_PATH . '/logs/finalDelete.log');
        return $this->executeSparqlUpdate($endpoint, $query);
    }
        
        /**
     * Delete a resource from GraphDB using patterns based on Omeka ID
     *
     * @param string $endpoint GraphDB endpoint
     * @param string $graphUri Graph URI
     * @param int $itemId Omeka item ID
     */
    private function deleteResourceByOmekaId($endpoint, $graphUri, $itemId)
    {
        // First, log what we're trying to do
        error_log("Attempting to delete resource related to Omeka item ID $itemId from graph $graphUri", 3, OMEKA_PATH . '/logs/finalDelete.log');
        
        // Build a SPARQL query to delete resources that might be related to this Omeka item
        // This is a fallback when we don't have the original identifier
        $query = "
            PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
            
            WITH <$graphUri>
            DELETE {
                ?s ?p ?o .
                ?related ?rel ?s .
            }
            WHERE {
                # Try to match resources that might be related to this Omeka item
                ?s ?anyProp ?anyValue .
                FILTER(CONTAINS(STR(?s), '$itemId'))
                
                # Get all properties and values
                ?s ?p ?o .
                
                # Optional pattern to find resources that reference this one
                OPTIONAL {
                    ?related ?rel ?s .
                }
            }
        ";
        
        error_log("SPARQL Query: $query", 3, OMEKA_PATH . '/logs/finalDelete.log');
        return $this->executeSparqlUpdate($endpoint, $query);
    }
    
    /**
     * Execute a SPARQL UPDATE query against GraphDB
     *
     * @param string $endpoint GraphDB endpoint
     * @param string $query SPARQL query
     * @return \Laminas\Http\Response
     * @throws \Exception
     */
    private function executeSparqlUpdate($endpoint, $query)
    {
        $client = new Client();
        $client->setMethod('POST');
        $client->setUri($endpoint);
        $client->setHeaders([
            'Content-Type' => 'application/sparql-update',
            'Accept' => 'application/json'
        ]);
        $client->setRawBody($query);
        
        try {
            $response = $client->send();
            
            // Log both success and failure
            $statusCode = $response->getStatusCode();
            if ($response->isSuccess()) {
                error_log("GraphDB query executed successfully: Status $statusCode", 3, OMEKA_PATH . '/logs/finalDelete.log');
            } else {
                $errorMsg = "GraphDB query failed: " . $statusCode . " - " . $response->getBody();
                error_log($errorMsg, 3, OMEKA_PATH . '/logs/finalDelete.log');
                throw new \Exception($errorMsg);
            }
            
            return $response;
        } catch (\Exception $e) {
            error_log("Exception when executing SPARQL query: " . $e->getMessage(), 3, OMEKA_PATH . '/logs/finalDelete.log');
            throw $e;
        }
    }
    
    /**
     * Get service locator
     * 
     * @return ServiceLocatorInterface
     */
    public function getServiceLocator()
    {
        return $this->serviceLocator;
    }

    /**
     * Track item-to-itemset relationships when items are created or updated
     */
    public function trackItemItemSetRelationship($event)
    {
        $item = $event->getParam('response')->getContent();
        
        if (!$item instanceof \Omeka\Api\Representation\ItemRepresentation) {
            return;
        }
        
        $itemId = $item->id();
        $itemSets = $item->itemSets();
        
        if (empty($itemSets)) {
            error_log("Item $itemId has no item sets", 3, OMEKA_PATH . '/logs/finalDelete.log');
            return;
        }
        
        // Store mapping in module settings
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $itemItemSetMap = $settings->get('addtriplestore_item_itemset_map', []);
        
        foreach ($itemSets as $itemSet) {
            $itemSetId = $itemSet->id();
            $itemItemSetMap[$itemId] = $itemSetId;
            error_log("Tracking item $itemId in item set $itemSetId ({$itemSet->title()})", 3, OMEKA_PATH . '/logs/finalDelete.log');
            break; // Only store the first item set for simplicity
        }
        
        $settings->set('addtriplestore_item_itemset_map', $itemItemSetMap);
        error_log("Updated item-itemset mapping in module settings", 3, OMEKA_PATH . '/logs/finalDelete.log');
    }
}