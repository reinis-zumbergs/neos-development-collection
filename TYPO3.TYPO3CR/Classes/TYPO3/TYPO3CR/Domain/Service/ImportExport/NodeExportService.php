<?php
namespace TYPO3\TYPO3CR\Domain\Service\ImportExport;

/*
 * This file is part of the TYPO3.TYPO3CR package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Security\Context;
use TYPO3\TYPO3CR\Exception\ExportException;

/**
 * Service for exporting content repository nodes as an XML structure
 *
 * Internally, uses associative arrays instead of Domain Models for performance reasons, so "nodeData" in this
 * class is always an associative array.
 *
 * @Flow\Scope("singleton")
 */
class NodeExportService
{
    /**
     * @var string
     */
    const SUPPORTED_FORMAT_VERSION = '2.0';

    /**
     * @Flow\Inject
     * @var \TYPO3\Flow\Log\SystemLoggerInterface
     */
    protected $systemLogger;

    /**
     * @Flow\Inject
     * @var \TYPO3\Flow\Object\ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * @Flow\Inject
     * @var \TYPO3\Flow\Persistence\PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject
     * @var \TYPO3\Flow\Property\PropertyMapper
     */
    protected $propertyMapper;

    /**
     * Doctrine's Entity Manager. Note that "ObjectManager" is the name of the related
     * interface ...
     *
     * @Flow\Inject
     * @var \Doctrine\Common\Persistence\ObjectManager
     */
    protected $entityManager;

    /**
     * @Flow\Inject
     * @var \TYPO3\TYPO3CR\Domain\Service\NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * @Flow\Inject
     * @var \TYPO3\TYPO3CR\Domain\Repository\NodeDataRepository
     */
    protected $nodeDataRepository;

    /**
     * @Flow\Inject
     * @var Context
     */
    protected $securityContext;

    /**
     * @var ImportExportPropertyMappingConfiguration
     */
    protected $propertyMappingConfiguration;

    /**
     * @var \XMLWriter
     */
    protected $xmlWriter;

    /**
     * @var array<\Exception> a list of exceptions which happened during export
     */
    protected $exceptionsDuringExport;

    /**
     * @var array Node paths that have been exported, this is used for consistency checks of broken node rootlines
     */
    protected $exportedNodePaths;

    /**
     * Exports the node data of all nodes in the given sub-tree
     * by writing them to the given XMLWriter.
     *
     * @param string $startingPointNodePath path to the root node of the sub-tree to export. The specified node will not be included, only its sub nodes.
     * @param string $workspaceName
     * @param \XMLWriter $xmlWriter
     * @param boolean $tidy
     * @param boolean $endDocument
     * @param string $resourceSavePath
     * @param string $nodeTypeFilter Filter the node type of the nodes, allows complex expressions (e.g. "TYPO3.Neos:Page", "!TYPO3.Neos:Page,TYPO3.Neos:Text")
     * @return \XMLWriter
     */
    public function export($startingPointNodePath = '/', $workspaceName = 'live', \XMLWriter $xmlWriter = null, $tidy = true, $endDocument = true, $resourceSavePath = null, $nodeTypeFilter = null)
    {
        $this->propertyMappingConfiguration = new ImportExportPropertyMappingConfiguration($resourceSavePath);
        $this->exceptionsDuringExport = array();
        $this->exportedNodePaths = array();
        if ($startingPointNodePath !== '/') {
            $startingPointParentPath = substr($startingPointNodePath, 0, strrpos($startingPointNodePath, '/'));
            $this->exportedNodePaths[$startingPointParentPath] = true;
        }

        $this->xmlWriter = $xmlWriter;
        if ($this->xmlWriter === null) {
            $this->xmlWriter = new \XMLWriter();
            $this->xmlWriter->openMemory();
            $this->xmlWriter->setIndent($tidy);
            $this->xmlWriter->startDocument('1.0', 'UTF-8');
        }

        $this->securityContext->withoutAuthorizationChecks(function () use ($startingPointNodePath, $workspaceName, $nodeTypeFilter) {
            $nodeDataList = $this->findNodeDataListToExport($startingPointNodePath, $workspaceName, $nodeTypeFilter);
            $this->exportNodeDataList($nodeDataList);
        });

        if ($endDocument) {
            $this->xmlWriter->endDocument();
        }

        $this->handleExceptionsDuringExport();

        return $this->xmlWriter;
    }

    /**
     * Find all nodes of the specified workspace lying below the path specified by
     * (and including) the given starting point.
     *
     * @param string $pathStartingPoint Absolute path specifying the starting point
     * @param string $workspace The containing workspace
     * @param string $nodeTypeFilter
     * @return array an array of node-data in array format.
     */
    protected function findNodeDataListToExport($pathStartingPoint, $workspace = 'live', $nodeTypeFilter = null)
    {
        /** @var \Doctrine\ORM\QueryBuilder $queryBuilder */
        $queryBuilder = $this->entityManager->createQueryBuilder();
        $queryBuilder->select(
            'n.path AS path,'
            . ' n.identifier AS identifier,'
            . ' n.index AS sortingIndex,'
            . ' n.properties AS properties, '
            . ' n.nodeType AS nodeType,'
            . ' n.removed AS removed,'
            . ' n.hidden,'
            . ' n.hiddenBeforeDateTime AS hiddenBeforeDateTime,'
            . ' n.hiddenAfterDateTime AS hiddenAfterDateTime,'
            . ' n.creationDateTime AS creationDateTime,'
            . ' n.lastModificationDateTime AS lastModificationDateTime,'
            . ' n.lastPublicationDateTime AS lastPublicationDateTime,'
            . ' n.hiddenInIndex AS hiddenInIndex,'
            . ' n.accessRoles AS accessRoles,'
            . ' n.version AS version,'
            . ' n.parentPath AS parentPath,'
            . ' n.pathHash AS pathHash,'
            . ' n.dimensionsHash AS dimensionsHash,'
            . ' n.parentPathHash AS parentPathHash,'
            . ' n.dimensionValues AS dimensionValues,'
            . ' w.name AS workspace'
        )->distinct()
            ->from('TYPO3\TYPO3CR\Domain\Model\NodeData', 'n')
            ->innerJoin('n.workspace', 'w', 'WITH', 'n.workspace=w.name')
            ->where('n.workspace = :workspace')
            ->setParameter('workspace', $workspace)
            ->andWhere('n.path = :pathPrefix OR n.path LIKE :pathPrefixMatch')
            ->setParameter('pathPrefix', $pathStartingPoint)
            ->setParameter('pathPrefixMatch', ($pathStartingPoint === '/' ? '%' : $pathStartingPoint . '/%'))
            ->orderBy('n.identifier', 'ASC')
            ->orderBy('n.path', 'ASC');

        if ($nodeTypeFilter) {
            $this->nodeDataRepository->addNodeTypeFilterConstraintsToQueryBuilder($queryBuilder, $nodeTypeFilter);
        }

        $nodeDataList = $queryBuilder->getQuery()->getResult();
        // Sort nodeDataList by path, replacing "/" with "!" (the first visible ASCII character)
        // because there may be characters like "-" in the node path
        // that would break the sorting order
        usort($nodeDataList,
            function ($node1, $node2) {
                return strcmp(
                    str_replace("/", "!", $node1['path']),
                    str_replace("/", "!", $node2['path'])
                );
            }
        );
        return $nodeDataList;
    }

    /**
     * Exports the given Nodes into the XML structure, contained in <nodes> </nodes> tags.
     *
     * @param array $nodeDataList The nodes to export
     * @return void The result is written directly into $this->xmlWriter
     */
    protected function exportNodeDataList(array &$nodeDataList)
    {
        $this->xmlWriter->startElement('nodes');
        $this->xmlWriter->writeAttribute('formatVersion', self::SUPPORTED_FORMAT_VERSION);

        $nodesStack = array();
        foreach ($nodeDataList as $nodeData) {
            $this->exportNodeData($nodeData, $nodesStack);
        }

        // Close remaining <node> tags according to the stack:
        while (array_pop($nodesStack)) {
            $this->xmlWriter->endElement();
        }

        $this->xmlWriter->endElement();
    }

    /**
     * Exports a single Node into the XML structure
     *
     * @param array $nodeData The node data as an array
     * @param array $nodesStack The stack keeping track of open tags, as passed by exportNodeDataList()
     * @return void The result is written directly into $this->xmlWriter
     */
    protected function exportNodeData(array &$nodeData, array &$nodesStack)
    {
        if ($nodeData['path'] !== '/' && !isset($this->exportedNodePaths[$nodeData['parentPath']])) {
            $this->xmlWriter->writeComment(sprintf('Skipped node with identifier "%s" and path "%s" because of a missing parent path. This is caused by a broken rootline and needs to be fixed with the "node:repair" command.', $nodeData['identifier'], $nodeData['path']));
            return;
        }

        $this->exportedNodePaths[$nodeData['path']] = true;

        if ($nodeData['parentPath'] === '/') {
            $nodeName = substr($nodeData['path'], 1);
        } else {
            $nodeName = substr($nodeData['path'], strlen($nodeData['parentPath']) + 1);
        }

        // is this a variant of currently open node?
        // then close all open nodes until parent is currently open and start new node element
        // else reuse the currently open node element and add a new variant element
        // @todo what about nodes with a different path in some dimension
        $parentNode = end($nodesStack);
        if (!$parentNode || $parentNode['path'] !== $nodeData['path'] || $parentNode['identifier'] !== $nodeData['identifier']) {
            while ($parentNode && $nodeData['parentPath'] !== $parentNode['path']) {
                $this->xmlWriter->endElement();
                array_pop($nodesStack);
                $parentNode = end($nodesStack);
            }

            $nodesStack[] = $nodeData;
            $this->xmlWriter->startElement('node');
            $this->xmlWriter->writeAttribute('identifier', $nodeData['identifier']);
            $this->xmlWriter->writeAttribute('nodeName', $nodeName);
        }

        $this->xmlWriter->startElement('variant');

        if ($nodeData['sortingIndex'] !== null) {
            // the "/" node has no sorting index by default; so we should only write it if it has been set.
            $this->xmlWriter->writeAttribute('sortingIndex', $nodeData['sortingIndex']);
        }

        foreach (
            array(
                'workspace',
                'nodeType',
                'version',
                'removed',
                'hidden',
                'hiddenInIndex'
            ) as $propertyName) {
            $this->xmlWriter->writeAttribute($propertyName, $nodeData[$propertyName]);
        }

        $this->xmlWriter->startElement('dimensions');
        foreach ($nodeData['dimensionValues'] as $dimensionKey => $dimensionValues) {
            foreach ($dimensionValues as $dimensionValue) {
                $this->xmlWriter->writeElement($dimensionKey, $dimensionValue);
            }
        }
        $this->xmlWriter->endElement();

        foreach (
            array(
                'accessRoles',
                'hiddenBeforeDateTime',
                'hiddenAfterDateTime',
                'creationDateTime',
                'lastModificationDateTime',
                'lastPublicationDateTime',
                'contentObjectProxy'
            ) as $propertyName) {
            $this->writeConvertedElement($nodeData, $propertyName);
        }

        $this->xmlWriter->startElement('properties');
        if ($this->nodeTypeManager->hasNodeType($nodeData['nodeType'])) {
            $nodeType = $this->nodeTypeManager->getNodeType($nodeData['nodeType']);

            foreach ($nodeData['properties'] as $propertyName => $propertyValue) {
                if ($nodeType->hasConfiguration('properties.' . $propertyName)) {
                    $declaredPropertyType = $nodeType->getPropertyType($propertyName);
                    $this->writeConvertedElement($nodeData['properties'], $propertyName, null, $declaredPropertyType);
                }
            }
        } else {
            foreach ($nodeData['properties'] as $propertyName => $propertyValue) {
                $this->writeConvertedElement($nodeData['properties'], $propertyName);
            }
        }
        $this->xmlWriter->endElement(); // "properties"

        $this->xmlWriter->endElement(); // "variant"
    }

    /**
     * Writes out a single property into the XML structure.
     *
     * @param array $data The data as an array, the given property name is looked up there
     * @param string $propertyName The name of the property
     * @param string $elementName an optional name to use, defaults to $propertyName
     * @return void
     */
    protected function writeConvertedElement(array &$data, $propertyName, $elementName = null, $declaredPropertyType = null)
    {
        if (array_key_exists($propertyName, $data) && $data[$propertyName] !== null) {
            $propertyValue = $data[$propertyName];
            $this->xmlWriter->startElement($elementName ?: $propertyName);

            if (!empty($propertyValue)) {
                switch ($declaredPropertyType) {
                    case null:
                    case 'reference':
                    case 'references':
                        break;
                    default:
                        $propertyValue = $this->propertyMapper->convert($propertyValue, $declaredPropertyType);
                        break;
                }
            }

            $this->xmlWriter->writeAttribute('__type', gettype($propertyValue));
            try {
                if (is_object($propertyValue) && !$propertyValue instanceof \DateTime) {
                    $objectIdentifier = $this->persistenceManager->getIdentifierByObject($propertyValue);
                    if ($objectIdentifier !== null) {
                        $this->xmlWriter->writeAttribute('__identifier', $objectIdentifier);
                    }
                    if ($propertyValue instanceof \Doctrine\ORM\Proxy\Proxy) {
                        $className = get_parent_class($propertyValue);
                    } else {
                        $className = get_class($propertyValue);
                    }
                    $this->xmlWriter->writeAttribute('__classname', $className);
                    $this->xmlWriter->writeAttribute('__encoding', 'json');

                    $converted = json_encode($this->propertyMapper->convert($propertyValue, 'array', $this->propertyMappingConfiguration));
                    $this->xmlWriter->text($converted);
                } elseif (is_array($propertyValue)) {
                    foreach ($propertyValue as $key => $element) {
                        $this->writeConvertedElement($propertyValue, $key, 'entry' . $key);
                    }
                } else {
                    if ($propertyValue instanceof \DateTime) {
                        $this->xmlWriter->writeAttribute('__classname', 'DateTime');
                    }
                    $this->xmlWriter->text($this->propertyMapper->convert($propertyValue, 'string', $this->propertyMappingConfiguration));
                }
            } catch (\Exception $exception) {
                $this->xmlWriter->writeComment(sprintf('Could not convert property "%s" to string.', $propertyName));
                $this->xmlWriter->writeComment($exception->getMessage());
                $this->systemLogger->logException($exception);
                $this->exceptionsDuringExport[] = $exception;
            }

            $this->xmlWriter->endElement();
        }
    }

    /**
     * If $this->exceptionsDuringImport is non-empty, build up a new composite exception which contains the individual messages and
     * re-throw that one.
     */
    protected function handleExceptionsDuringExport()
    {
        if (count($this->exceptionsDuringExport) > 0) {
            $exceptionMessages = '';
            foreach ($this->exceptionsDuringExport as $i => $exception) {
                $exceptionMessages .= "\n" . $i . ': ' . get_class($exception) . "\n" . $exception->getMessage() . "\n";
            }

            throw new ExportException(sprintf('%s exceptions occurred during export. Please see the log for the full exceptions (including stack traces). The exception messages follow below: %s', count($this->exceptionsDuringExport), $exceptionMessages), 1409057360);
        }
    }
}
