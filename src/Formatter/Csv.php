<?php
namespace BulkExport\Formatter;

use BulkExport\Traits\ListTermsTrait;
use BulkExport\Traits\MetadataToStringTrait;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

class Csv extends AbstractFormatter
{
    use ListTermsTrait;
    use MetadataToStringTrait;

    protected $label = 'csv';
    protected $extension = 'csv';
    protected $responseHeaders = [
        'Content-type' => 'text/csv',
    ];
    protected $defaultOptions = [
        'delimiter' => ',',
        'enclosure' => '"',
        'escape' => '\\',
        'separator' => ' | ',
        'has_separator' => true,
        'format_generic' => 'raw',
        'format_resource' => 'identifier_id',
        'format_resource_property' => 'dcterms:identifier',
        'format_uri' => 'uri_label',
    ];

    protected function process()
    {
        $this->initializeOutput();
        if ($this->hasError) {
            return;
        }

        // TODO Add a check for the separator in the values.

        // First loop to get all headers.
        $rowHeaders = $this->prepareHeaders();

        fputcsv($this->handle, array_keys($rowHeaders), $this->options['delimiter'], $this->options['enclosure'], $this->options['escape']);

        $outputRowForResource = function (AbstractResourceEntityRepresentation $resource) use ($rowHeaders) {
            $row = $this->prepareRow($resource, $rowHeaders);
            // Do a diff to avoid issue if a resource was update during process.
            // Order the row according to headers, keeping empty values.
            $row = array_values(array_replace($rowHeaders, array_intersect_key($row, $rowHeaders)));
            fputcsv($this->handle, $row, $this->options['delimiter'], $this->options['enclosure'], $this->options['escape']);
        };

        // Second loop to fill each row.
        /** @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource */
        if ($this->isId) {
            foreach ($this->resourceIds as $resourceId) {
                try {
                    $resource = $this->api->read($this->resourceType, ['id' => $resourceId])->getContent();
                } catch (\Omeka\Api\Exception\NotFoundException $e) {
                    continue;
                }
                $outputRowForResource($resource);
            }
        } else {
            array_walk($this->resources, $outputRowForResource);
        }

        $this->finalizeOutput();
    }

    /**
     * Get all headers used in all resources.
     *
     * @return array Associative array with keys as headers and null as value.
     */
    protected function prepareHeaders()
    {
        $rowHeaders = [
            'o:id' => true,
            'url' => true,
            'resource_type' => false,
            'o:resource_class' => true,
            'o:item_set[dcterms:title]' => false,
            'o:item[dcterms:title]' => false,
            'o:media[o:id]' => false,
            'o:media[media_type]' => false,
            'o:media[size]' => false,
            'o:media[original_url]' => false,
        ];
        // TODO Get only the used properties of the resources.
        $rowHeaders += array_fill_keys(array_keys($this->getPropertiesByTerm()), false);

        $resourceTypes = [];

        // TODO Get all data from one or two sql requests (and rights checks) (see AbstractSpreadsheet).

        /** @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource */
        if ($this->isId) {
            foreach ($this->resourceIds as $key => $resourceId) {
                try {
                    $resource = $this->api->read($this->resourceType, ['id' => $resourceId])->getContent();
                } catch (\Omeka\Api\Exception\NotFoundException $e) {
                    unset($this->resourceIds[$key]);
                    continue;
                }
                $resourceTypes[$resource->resourceName()] = true;
                $rowHeaders = array_replace($rowHeaders, array_fill_keys(array_keys($resource->values()), true));
            }
        } else {
            foreach ($this->resources as $resource) {
                $resourceTypes[$resource->resourceName()] = true;
                $rowHeaders = array_replace($rowHeaders, array_fill_keys(array_keys($resource->values()), true));
            }
        }

        $resourceTypes = array_filter($resourceTypes);
        if (count($resourceTypes) > 1) {
            $rowHeaders['resource_type'] = true;
        }
        foreach (array_keys($resourceTypes) as $resourceType) {
            switch ($resourceType) {
                case 'items':
                    $rowHeaders = array_replace($rowHeaders, [
                        'o:item_set[dcterms:title]' => true,
                        'o:media[o:id]' => true,
                        'o:media[original_url]' => true,
                    ]);
                    break;
                case 'media':
                    $rowHeaders = array_replace($rowHeaders, [
                        'o:item[dcterms:title]' => true,
                        'o:media[media_type]' => true,
                        'o:media[size]' => true,
                        'o:media[original_url]' => true,
                    ]);
                    break;
                default:
                    break;
            }
        }

        return array_fill_keys(array_keys(array_filter($rowHeaders)), null);
    }

    protected function prepareRow(AbstractResourceEntityRepresentation $resource, array $rowHeaders)
    {
        $row = [];
        $row['o:id'] = $resource->id();
        $row['url'] = $resource->url(null, true);
        // Manage an exception.
        if (array_key_exists('resource_type', $rowHeaders)) {
            $row['resource_type'] = basename(get_class($resource));
        }
        $resourceClass = $resource->resourceClass();
        $row['o:resource_class'] = $resourceClass ? $resourceClass->term() : '';

        $resourceName = $resource->resourceName();
        switch ($resourceName) {
            case 'items':
                /** @var \Omeka\Api\Representation\ItemRepresentation @resource */
                $values = $this->stringMetadata($resource, 'o:item_set[dcterms:title]');
                $row['o:item_set[dcterms:title]'] = implode($this->options['separator'], $values);
                $values = $this->stringMetadata($resource, 'o:media[o:id]');
                $row['o:media[o:id]'] = implode($this->options['separator'], $values);
                $values = $this->stringMetadata($resource, 'o:media[original_url]');
                $row['o:media[original_url]'] = implode($this->options['separator'], $values);
                break;

            case 'media':
                /** @var \Omeka\Api\Representation\MediaRepresentation @resource */
                $row['o:item[dcterms:title]'] = $resource->item()->url();
                $row['o:media[media_type]'] = $resource->mediaType();
                $row['o:media[size]'] = $resource->size();
                $row['o:media[original_url]'] = $resource->originalUrl();
                break;

            case 'item_sets':
                /** @var \Omeka\Api\Representation\ItemSetRepresentation @resource */
                // Nothing to do.
                break;

            default:
                break;
        }

        foreach (array_keys($resource->values()) as $term) {
            $values = $this->stringMetadata($resource, $term);
            $row[$term] = implode($this->options['separator'], $values);
        }

        return $row;
    }
}
