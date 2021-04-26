<?php

namespace App\Models;

use App\Libraries\NbnRecords;

/**
 * A facade for the NBN API
 *
 * Referenced at <https://api.nbnatlas.org/> the available search fields
 * listed at <https://records-ws.nbnatlas.org/index/fields>.
 */
class NbnQuery implements NbnQueryInterface
{
	/**
	 * Get an alphabetical list of species.
	 *
	 * e.g. https://records-ws.nbnatlas.org/explore/group/ALL_SPECIES?q=data_resource_uid:dr782&fq=taxon_name:B* AND species_group:Plants Bryophytes&pageSize=9&sort=
	 *
	 * TODO: Search in common names
	 * TODO: Search on axiophytes
	 * TODO: Only plants, only bryophytes or both
	 */
	public function getSpeciesListForCounty($name_search_string, $nameType, $speciesGroup, $page)
	{
		//because the API respects the case
		$name_search_string = ucfirst($name_search_string);

		if ($speciesGroup === "both")
		{
			$speciesGroup = 'Plants+Bryophytes';
		}
		else
		{
			$speciesGroup = ucfirst($speciesGroup);
		}
		$nbn_records = new NbnRecords('explore/group/ALL_SPECIES');

		if ($nameType === "scientific")
		{
			$nbn_records
				->add('taxon_name:' . str_replace(" ", "+%2B", $name_search_string) . '*')
				->sort = "taxon_name";
		}

		if ($nameType === "common")
		{
			$nbn_records
				->add('common_name:' . str_replace(" ", "+%2B", $name_search_string) . '*')
				->sort = "common_name";
		}
		$nbn_records->add('species_group:' . $speciesGroup);
		$query_url         = $nbn_records->getPagingQueryStringWithStart($page);
		$nbnQueryResponse  = $this->callNbnApi($query_url);

		$speciesQueryResult               = new NbnQueryResult();
		if ($nbnQueryResponse->status === 'OK')
		{
			$speciesQueryResult->records      = $nbnQueryResponse->jsonResponse;
			$speciesQueryResult->downloadLink = $nbn_records->getDownloadQueryString();
		}
		$speciesQueryResult->status   = $nbnQueryResponse->status;
		$speciesQueryResult->message  = $nbnQueryResponse->message;
		$speciesQueryResult->queryUrl = $query_url;
		return $speciesQueryResult;
	}

	/**
	 * Get the records for a single species
	 *
	 * e.g. https://records-ws.nbnatlas.org/occurrences/search?q=data_resource_uid:dr782&fq=taxon_name:Abies%20alba&sort=taxon_name&fsort=index&pageSize=9
	 *
	 * The taxon needs to be in double quotes so the complete string is searched for rather than a partial.
	 */
	public function getSingleSpeciesRecordsForCounty($speciesName, $page)
	{
		// mainly to replace the spaces with %20
		$speciesName      = rawurlencode($speciesName);
		$nbnRecords       = new NbnRecords('occurrences/search');
		$nbnRecords->sort = "year";
		$nbnRecords->dir  = "desc";
		// $nbnRecords->fsort = "index";
		$nbnRecords
			->add('taxon_name:' . '"' . $speciesName . '"');
		$queryUrl           = $nbnRecords->getPagingQueryStringWithStart($page);
		$recordsJson        = file_get_contents($queryUrl);
		$recordsJsonDecoded = json_decode($recordsJson);
		$recordList         = $recordsJsonDecoded->occurrences;
		$totalRecords       = $recordsJsonDecoded->totalRecords;
		usort($recordList, function ($a, $b) {
			return $b->year <=> $a->year;
		});

		$sites = [];
		foreach ($recordList as $record)
		{
			$record->locationId = $record->locationId ?? '';
			$record->collector  = $record->collector ?? 'Unknown';

			// To plot site markers on the map, we must capture the locationId
			// (site name) and latLong of only the _first_ record for each site.
			// The latLong returned from the API is a single string, so we
			// convert into an array of two floats.
			if (! array_key_exists($record->locationId, $sites))
			{
				$sites[$record->locationId] = array_map('floatval', explode(",", $record->latLong));
			}
		}

		$speciesQueryResult               = new NbnQueryResult();
		$speciesQueryResult->records      = $recordList;
		$speciesQueryResult->sites        = $sites;
		$speciesQueryResult->downloadLink = $nbnRecords->getDownloadQueryString();
		$speciesQueryResult->totalRecords = $totalRecords;
		$speciesQueryResult->queryUrl     = $queryUrl;
		return $speciesQueryResult;
	}

	/**
	 * Get a single record
	 *
	 * e.g. https://records-ws.nbnatlas.org/occurrence/4276e1be-b7d2-46b0-a33d-6fa82e97636a
	 */
	public function getSingleOccurenceRecord($uuid)
	{
		$nbnRecords = new NbnRecords('occurrence/');
		$recordJson = file_get_contents($nbnRecords->url() . $uuid);
		$record     = json_decode($recordJson);

		$singleOccurenceResult               = new NbnQueryResult();
		$singleOccurenceResult->records      = $record;
		$singleOccurenceResult->downloadLink = $nbnRecords->getDownloadQueryString();

		return $singleOccurenceResult;
	}

	/**
	 * Search for sites matching the string
	 *
	 * e.g. 'https://records-ws.nbnatlas.org/occurrences/search?fq=location_id:[Shrews%20TO%20*]&fq=data_resource_uid:dr782&facets=location_id&facet=on&pageSize=0';
	 */
	public function getSiteListForCounty($site_search_string)
	{
		$nbn_records           = new NbnRecords('occurrences/search');
		$nbn_records->facets   = "location_id";
		$nbn_records->pageSize = 0;
		$nbn_records
			->add('location_id:[' . $site_search_string . '%20TO%20*]');
		$query_url  = $nbn_records->getPagingQueryString();
		$sites_json = file_get_contents($query_url);
		$sites_list = json_decode($sites_json)->facetResults[0]->fieldResult;
		$sites_list = truncateArray(9, $sites_list);
		return $sites_list;
	}

	/**
	 * Get species list for a site.
	 *
	 * e.g. 'https://records-ws.nbnatlas.org/explore/group/ALL_SPECIES?q=&fq=data_resource_uid:dr782+AND+location_id:Shrewsbury+AND+species_group:Plants+Bryophytes&pageSize=9'
	 */
	public function getSpeciesListForSite($site_name, $species_group)
	{
		$nbn_records = new NbnRecords('explore/group/ALL_SPECIES');
		$nbn_records
			->add('location_id:' . urlencode($site_name))
			->add('species_group:Plants+Bryophytes');
		$query_url         = $nbn_records->getPagingQueryString();
		$species_json      = file_get_contents($query_url);
		$site_species_list = json_decode($species_json);
		return $site_species_list;
	}

	public function getSingleSpeciesRecordsForSite($site_name, $species_name)
	{
		return null;
	}

	public function getSpeciesListForSquare($grid_square, $species_group)
	{
		return null;
	}

	public function getSingleSpeciesRecordsForSquare($grid_square, $species_name)
	{
		return null;
	}

	private function callNbnApi($queryUrl)
	{
		$nbnApiResponse = new NbnApiResponse();
		try
		{
			$jsonResults                  = file_get_contents($queryUrl);
			$nbnApiResponse->jsonResponse = json_decode($jsonResults);
			$nbnApiResponse->status       = 'OK';
		}
		catch (\Throwable $e)
		{
			$nbnApiResponse->status = 'ERROR';
			$errorMessage           = $e->getMessage();
			if (strpos($errorMessage, '400 Bad Request') !== false)
			{
				$errorMessage = 'It looks like there is a problem with the query.  Here are the details: ' . $errorMessage;
			}
			if (strpos($errorMessage, '500') !== false)
			{
				$errorMessage = 'It looks like there is a problem with the NBN API.  Here are the details: ' . $errorMessage;
			}
			$nbnApiResponse->message = $errorMessage;
		}
		return $nbnApiResponse;
	}
}
