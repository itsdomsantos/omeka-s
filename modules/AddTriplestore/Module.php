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
    // Get the deleted item ID from the request
    $request = $event->getParam('request');
    if (!$request) {
        error_log("No request in event parameters", 3, OMEKA_PATH . '/logs/finalDelete.log');
        return;
    }

    $itemId = $request->getId();
    error_log("Item deletion detected: ID=$itemId", 3, OMEKA_PATH . '/logs/finalDelete.log');
    
    if (!$itemId) {
        error_log("Failed to retrieve item ID", 3, OMEKA_PATH . '/logs/finalDelete.log');
        return;
    }
    
    // Get session storage
    $session = new \Laminas\Session\Container('AddTriplestore');
    
    // Check if we've already processed this deletion
    if (isset($session->processedDeletions) && in_array($itemId, $session->processedDeletions)) {
        error_log("Skipping duplicate deletion event for item $itemId", 3, OMEKA_PATH . '/logs/finalDelete.log');
        return;
    }
    
    // Initialize the array if it doesn't exist
    if (!isset($session->processedDeletions)) {
        $session->processedDeletions = [];
    }
    
    // Mark this item as processed
    $session->processedDeletions[] = $itemId;
    
    // Get the settings manager
    $settings = $this->getServiceLocator()->get('Omeka\Settings');
    
    // Get the pre-deletion info
    $itemDeletionInfo = $settings->get('addtriplestore_item_deletion_info', []);
    
    $identifier = null;
    $graphId = "0"; // Default graph
    
    // Check if we have pre-deletion info for this item
    if (isset($itemDeletionInfo[$itemId])) {
        $info = $itemDeletionInfo[$itemId];
        $identifier = $info['identifier'] ?? null;
        $itemSetIds = $info['itemSetIds'] ?? [];
        
        if (!empty($itemSetIds)) {
            $graphId = $itemSetIds[0]; // Use the first item set as the graph ID
            error_log("Using pre-deletion item set ID as graph ID: $graphId", 3, OMEKA_PATH . '/logs/finalDelete.log');
        }
        
        // Remove this item from the deletion info
        unset($itemDeletionInfo[$itemId]);
        $settings->set('addtriplestore_item_deletion_info', $itemDeletionInfo);
    } else {
        // Fallback to the general item-itemset mapping
        $itemItemSetMap = $settings->get('addtriplestore_item_itemset_map', []);
        
        if (isset($itemItemSetMap[$itemId])) {
            $graphId = $itemItemSetMap[$itemId];
            error_log("Using mapped item set ID as graph ID: $graphId", 3, OMEKA_PATH . '/logs/finalDelete.log');
            
            // Remove this item from the mapping
            unset($itemItemSetMap[$itemId]);
            $settings->set('addtriplestore_item_itemset_map', $itemItemSetMap);
        } else {
            // Fallback to database query
            try {
                $entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');
                $connection = $entityManager->getConnection();
                
                // Try to get the item sets this item belongs to
                $itemSetRows = $connection->fetchAllAssociative(
                    "SELECT item_set_id FROM item_item_set WHERE item_id = ?", 
                    [$itemId]
                );
                
                if (!empty($itemSetRows)) {
                    $graphId = $itemSetRows[0]['item_set_id'];
                    error_log("Found item set ID from database: $graphId", 3, OMEKA_PATH . '/logs/finalDelete.log');
                }
                
                // Try to get the identifier value
                $identifierPropertyId = $connection->fetchOne(
                    'SELECT id FROM property WHERE local_name = ? AND vocabulary_id = (SELECT id FROM vocabulary WHERE prefix = ?)',
                    ['identifier', 'dcterms']
                );
                
                if ($identifierPropertyId) {
                    $identifierValue = $connection->fetchOne(
                        'SELECT value FROM value WHERE resource_id = ? AND property_id = ? LIMIT 1',
                        [$itemId, $identifierPropertyId]
                    );
                    
                    if ($identifierValue) {
                        $identifier = $identifierValue;
                        error_log("Found identifier value from database: $identifier", 3, OMEKA_PATH . '/logs/finalDelete.log');
                    }
                }
            } catch (\Exception $e) {
                error_log("Error in database fallback: " . $e->getMessage(), 3, OMEKA_PATH . '/logs/finalDelete.log');
            }
        }
    }
    
    // Log the final values we're using
    error_log("Final values for deleteFromGraphDB: identifier=$identifier, itemId=$itemId, graphId=$graphId", 3, OMEKA_PATH . '/logs/finalDelete.log');
    
    // Delete from GraphDB using the item set ID directly as the graph ID
    $this->deleteFromGraphDB($identifier, $itemId, $graphId);
    
    // Add a method to clean up old processed deletions (to avoid session bloat)
    $this->cleanupProcessedDeletions($session);
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
    // GraphDB configuration - update these endpoints to match your setup
    $graphdbEndpoint = "http://localhost:7200/repositories/megalod/statements";
    $baseDataGraphUri = "https://purl.org/megalod/";
    $graphUri = $baseDataGraphUri . $graphId . "/";
    
    error_log("Attempting to delete from GraphDB: identifier=$identifier, itemId=$itemId, graphUri=$graphUri", 3, OMEKA_PATH . '/logs/finalDelete.log');
    
    try {
        // First try the specific graph
        $deleted = false;
        
        // Different delete strategies based on available information
        if ($identifier) {
            // If we have a specific identifier, target resources with that identifier
            error_log("Using identifier-based deletion strategy", 3, OMEKA_PATH . '/logs/finalDelete.log');
            $result = $this->deleteResourceByIdentifier($graphdbEndpoint, $graphUri, $identifier);
            $deleted = $result->isSuccess();
        } else {
            // If no identifier, try to delete based on Omeka item ID patterns
            error_log("Using ID-based deletion strategy as fallback", 3, OMEKA_PATH . '/logs/finalDelete.log');
            $result = $this->deleteResourceByOmekaId($graphdbEndpoint, $graphUri, $itemId);
            $deleted = $result->isSuccess();
        }
        
        // If we failed to delete from the specific graph and we're not already trying the default,
        // try the default graph as a fallback
        if (!$deleted && $graphId !== "0") {
            $defaultGraphUri = $baseDataGraphUri . "0/";
            error_log("First attempt failed, trying default graph: $defaultGraphUri", 3, OMEKA_PATH . '/logs/finalDelete.log');
            
            if ($identifier) {
                $this->deleteResourceByIdentifier($graphdbEndpoint, $defaultGraphUri, $identifier);
            } else {
                $this->deleteResourceByOmekaId($graphdbEndpoint, $defaultGraphUri, $itemId);
            }
        }
        
        error_log("Deletion from GraphDB completed for item $itemId", 3, OMEKA_PATH . '/logs/finalDelete.log');
    } catch (\Exception $e) {
        error_log("Error deleting3 from GraphDB: " . $e->getMessage(), 3, OMEKA_PATH . '/logs/finalDelete.log');
    }
}
    
    /**
     * Delete a resource from GraphDB using its identifier
     *
     * @param string $endpoint GraphDB endpoint
     * @param string $graphUri Graph URI
     * @param string $identifier Resource identifier
     */
    private function deleteResourceByIdentifier($endpoint, $graphUri, $identifier)
    {
        // First, log what we're trying to do
        error_log("Attempting to delete resource with identifier '$identifier' from graph $graphUri", 3, OMEKA_PATH . '/logs/finalDelete.log');
        
        // Build a SPARQL query to delete the resource and related triples
$query =
"PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
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
      # The main arrowhead item and all its properties
      <https://purl.org/megalod/1067/item/{$identifier}> ?p ?o .
      BIND(<https://purl.org/megalod/1067/item/{$identifier}> AS ?s)
    }
    UNION
    {
      # All triples where the arrowhead is the object
      ?s ?p <https://purl.org/megalod/1067/item/{$identifier}> .
    }
    UNION
    {
      # All related chipping data
      <https://purl.org/megalod/1067/chipping/{$identifier}> ?p ?o .
      BIND(<https://purl.org/megalod/1067/chipping/{$identifier}> AS ?s)
    }
    UNION
    {
      # All triples where the chipping data is the object
      ?s ?p <https://purl.org/megalod/1067/chipping/{$identifier}> .
    }
    UNION
    {
      # All related coordinates data
      <https://purl.org/megalod/1067/coordinates/{$identifier}> ?p ?o .
      BIND(<https://purl.org/megalod/1067/coordinates/{$identifier}> AS ?s)
    }
    UNION
    {
      # All triples where the coordinates are the object
      ?s ?p <https://purl.org/megalod/1067/coordinates/{$identifier}> .
    }
    UNION
    {
      # All related depth data
      <https://purl.org/megalod/1067/depth/{$identifier}> ?p ?o .
      BIND(<https://purl.org/megalod/1067/depth/{$identifier}> AS ?s)
    }
    UNION
    {
      # All triples where the depth data is the object
      ?s ?p <https://purl.org/megalod/1067/depth/{$identifier}> .
    }
    UNION
    {
      # All related encounter event data
      <https://purl.org/megalod/1067/encounter/{$identifier}> ?p ?o .
      BIND(<https://purl.org/megalod/1067/encounter/{$identifier}> AS ?s)
    }
    UNION
    {
      # All triples where the encounter event is the object
      ?s ?p <https://purl.org/megalod/1067/encounter/{$identifier}> .
    }
    UNION
    {
      # All related morphology data
      <https://purl.org/megalod/1067/morphology/{$identifier}> ?p ?o .
      BIND(<https://purl.org/megalod/1067/morphology/{$identifier}> AS ?s)
    }
    UNION
    {
      # All triples where the morphology data is the object
      ?s ?p <https://purl.org/megalod/1067/morphology/{$identifier}> .
    }
    UNION
    {
      # All related typometry data (baseLength, bodyLength, height, thickness, width)
      VALUES ?typometry {
        <https://purl.org/megalod/1067/typometry/{$identifier}-baseLength>
        <https://purl.org/megalod/1067/typometry/{$identifier}-bodyLength>
        <https://purl.org/megalod/1067/typometry/{$identifier}-height>
        <https://purl.org/megalod/1067/typometry/{$identifier}-thickness>
        <https://purl.org/megalod/1067/typometry/{$identifier}-width>
      }
      ?typometry ?p ?o .
      BIND(?typometry AS ?s)
    }
    UNION
    {
      # All triples where the typometry data is the object
      VALUES ?typometry {
        <https://purl.org/megalod/1067/typometry/{$identifier}-baseLength>
        <https://purl.org/megalod/1067/typometry/{$identifier}-bodyLength>
        <https://purl.org/megalod/1067/typometry/{$identifier}-height>
        <https://purl.org/megalod/1067/typometry/{$identifier}-thickness>
        <https://purl.org/megalod/1067/typometry/{$identifier}-width>
      }
      ?s ?p ?typometry .
    }
    UNION
    {
      # All related weight data
      <https://purl.org/megalod/1067/weight/{$identifier}> ?p ?o .
      BIND(<https://purl.org/megalod/1067/weight/{$identifier}> AS ?s)
    }
    UNION
    {
      # All triples where the weight data is the object
      ?s ?p <https://purl.org/megalod/1067/weight/{$identifier}> .
    }
  }
}";

        error_log("SPARQL Query: $query", 3, OMEKA_PATH . '/logs/finalDelete.log');
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