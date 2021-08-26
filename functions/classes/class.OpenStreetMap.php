<?php

/**
 * https://wiki.osmfoundation.org/wiki/Terms_of_Use
 * https://operations.osmfoundation.org/policies/nominatim/
 *
 * **Requirements**
 *
 *   No heavy uses (an absolute maximum of 1 request per second).
 *   Provide a valid HTTP Referer or User-Agent identifying the application (stock User-Agents as set by http libraries will not do).
 *   Clearly display attribution as suitable for your medium.
 *   Data is provided under the ODbL license which requires to share alike (although small extractions are likely to be covered by fair usage / fair dealing).
 *
 * **Websites and Apps**
 *
 * Use that is directly triggered by the end-user (for example, user searches for something) is ok, provided that your number of users is moderate.
 * Note that the usage limits above apply per website/application: the sum of traffic by all your users should not exceed the limits.
 *
 * Apps must make sure that they can switch the service at our request at any time (in particular, switching should be possible without requiring a software update).
 * If at all possible, set up a proxy and also enable caching of requests.
 *
 * ====================================================
 * Service can be switched via SQL "UPDATE nominatim SET url='https://newurl/search' WHERE id=1;";
 */

class OpenStreetMap extends Common_functions {

    /**
     * List of locations & circuits added to map for de-duplication
     *
     * @var array
     */
    private $markers = [];

    /**
     * GeoJSON data (markers) for map.
     *
     * @var array
     */
    private $geodata = [];

    /**
     * polyLine data (circuits) for map.
     *
     * @var array
     */
    private $polydata = [];

    /**
     * Used for decoding the hash from base32
     *
     * @var string
     */
    protected $base32Mapping = "0123456789bcdefghjkmnpqrstuvwxyz";
	
    /**
     * Constructor
     *
     * @param   Database_PDO  $database
     * @return  void
     */
    public function __construct (Database_PDO $database) {
        parent::__construct();

        $this->Database = $database;
        $this->Result = new Result ();
    }

    ################### Mapping functions

    /**
     * Validate $object contains valid ->lat and ->long values
     *
     * @param   StdClass $object
     * @return  bool
     */
    private function validate_lat_long($object) {
        if (!is_object($object) || !property_exists($object,'lat') || !property_exists($object,'long') ) {
            return false;
        }
        if (filter_var($object->lat, FILTER_VALIDATE_FLOAT)===false || filter_var($object->long, FILTER_VALIDATE_FLOAT)===false) {
            return false;
        }
        return true;
    }

    /**
     * Add location object to map
     *
     * @param   StdClass  $location
     * @return  bool
     */
    public function add_location ($location) {
        return $this->add_object($location, 'locations');
    }

    /**
     * Add customer object to map
     *
     * @param   StdClass  $location
     * @return  bool
     */
    public function add_customer ($customer) {
        return $this->add_object($customer, 'customers');
    }

    /**
     * Add location/customer object to map
     *
     * @param   StdClass  $object
     * @param   string    $type
     * @return  bool
     */
    private function add_object($object, $type) {
        if (!$this->validate_lat_long($object)) {
            return false;
        }

        if ($type == "locations") {
            $title = escape_input($object->name);
            $desc  = escape_input($object->description);
            $id    = $object->id;
        } elseif ($type == "customers") {
            $title = escape_input($object->title);
            $desc  = escape_input($object->note);
            $id    = $object->title;
        } else {
            return false;
        }

        // Deduplicate map markers
        if (isset($this->markers["$type-$id"])) {
            return false;
        }
        $this->markers["$type-$id"] = 1;

        // Add geoJSON locaiton marker data to map
        $popuptxt = "<h5><a href='".create_link("tools", $type, $id)."'>".$title."</a></h5>";
        $popuptxt .= is_string($desc) ? "<span class=\'text-muted\'>".$desc."</span>" : "";
        $popuptxt = str_replace(["\r\n","\n","\r"], "<br>", $popuptxt);

        $this->geodata[] = ["type"=> "Feature",
                            "properties" => ["name" => $title, "popupContent" => $popuptxt],
                            "geometry"   => ["type" => "Point", "coordinates" => [$object->long, $object->lat]]
                           ];

        return true;
    }

    /**
     * Add circuit object to map
     *
     * @param   StdClass $location1  Location of A end
     * @param   StdClas  $location2  Location of B end
     * @param   StdClass $type       Circuit circuitType object (color & dotted)
     * @return  bool
     */
    public function add_circuit($location1, $location2, $type) {
        $this->add_location($location1);
        $this->add_location($location2);

        if (!$this->validate_lat_long($location1) || !$this->validate_lat_long($location2)) {
            return false;
        }

        // Deduplicate lines
        if (isset($this->markers["circuit-{$location1->id}-{$location2->id}"]) || isset($this->markers["circuit-{$location2->id}-{$location1->id}"])) {
            return false;
        }
        $this->markers["circuit-{$location1->id}-{$location2->id}"] = 1;
        $this->markers["circuit-{$location2->id}-{$location1->id}"] = 1;

        // Add polyLine data for circuit.
        $ctcolor   = (is_object($type) && isset($type->ctcolor))   ? $type->ctcolor   : "Red";
        $ctpattern = (is_object($type) && isset($type->ctpattern)) ? $type->ctpattern : "Solid";

        $this->polydata["$ctcolor::::$ctpattern"][] = [[$location1->lat, $location1->long],[$location2->lat, $location2->long]];

        return true;
    }

    /**
     * Output OpenStreetMap HTML/JS
     *
     * @param   null|int  $height
     * @return  void
     */
    public function map($height=null) {
        if (sizeof($this->geodata) == 0) {
            $this->Result->show("info",_("No Locations with coordinates configured"), false);
            return;
        }

        ?>
		<style>
			::-webkit-scrollbar {
		width: 10px;
		margin-left: 40px;
		}

		/* Track */
		::-webkit-scrollbar-track {
		background: #f7f7f7; 
		border-radius: 30px;
		}
		
		/* Handle */
		::-webkit-scrollbar-thumb {
		background: #d8d8d8; 
		border-radius: 30px;
		}

		/* Handle on hover */
		::-webkit-scrollbar-thumb:hover {
		background: #b9b9b9; 
		}
		.custom-scrollbar{
			overflow-y: hidden;
			
		}
		.custom-scrollbar:hover{
			overflow-y: scroll;
		}
		</style>
        <div style="width:100%; height:<?php print isset($height) ? $height : "600px" ?>;" id="map_overlay">
            <div id="map" style="width:100%; height:100%;"></div>
			<div id="osmapData"> </div>
        </div>
        <script>
            function osm_style(feature) {
                return feature.properties && feature.properties.style;
            }

            function osm_onEachFeature(feature, layer) {
                if (feature.properties && feature.properties.popupContent) {
                    layer.bindPopup(feature.properties.popupContent);
                }
            }

            function osm_point_to_circle(feature, latlng) {
                return L.circleMarker(latlng, {
                    radius: 8,
                    fillColor: "#ff7800",
                    color: "#000",
                    weight: 1,
                    opacity: 1,
                    fillOpacity: 0.8
                });
            }

            var geodata   = <?php print json_encode($this->geodata); ?>;
			var geopoints = <?php print json_encode($this->geodata); ?>;
            var polydata  = <?php print json_encode($this->polydata); ?>;

            var mapOptions = {
                preferCanvas: true,
                attributionControl: true,
                zoom: -1,
                fullscreenControl: true,
            }

            var geoJSONOptions = {
				maxZoom: 23,
                style: osm_style,
                onEachFeature: osm_onEachFeature,
                pointToLayer: osm_point_to_circle,
            }

            var layerOptions = {
                attribution: 'Map data &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
            }
			<?php 
				$dir = './app/tools/locations/';
				$ficheros = scandir($dir);
				$folders = [];
				foreach($ficheros as $item){
					if(is_dir($dir . '/' . $item) && $item != '.' && $item != '..'){
						array_push($folders, $item);
					}
				}
			?>
			var folders = <?php echo json_encode($folders); ?>;
			
			var ZOOMLEVELS = {1: 4, 14: 4, 15 : 5, 16 : 6, 17 : 7, 18 : 9, 19 : 10, 20 : 10, 21: 10, 22: 10, 23: 10};
			
			var baseMaps;
			
			var hashes;
			
			var geojs = [];
			
            // Creating a map object
            var map = new L.map('map', mapOptions);

            // Add circuit lines
            for(var key in polydata) {
                var fmt = key.split('::::');
                if (fmt[1] == "Solid") {
                    L.polyline(polydata[key], {'color': fmt[0]}).addTo(map);
                } else {
                    L.polyline(polydata[key], {'color': fmt[0], dashArray: '20, 10'}).addTo(map);
                }
            };

            // Add location markers
            var geoJSON = L.geoJSON(geodata, geoJSONOptions);//.addTo(map);
            map.fitBounds(geoJSON.getBounds());
			
			// Add marker points to map
			loadmarkers(map);
			
			// Add default layers
			mapInitTitleLayers(map);
			
			// Add custom tile layers located in server's folder
			addlayers(map,folders);
			
			map.on('contextmenu', function (e) { $("#osmapData").html("Lat, Lon : " + e.latlng.lat.toFixed(8) + ", " + e.latlng.lng.toFixed(8)); });

			map.on('zoomstart', function() {
				//delete markers due to new zoom level
				for(geoj of geojs){geoj.remove();}
			});
			map.on('zoomend', function() {
				//add markers due to new zoom level
				loadmarkers(map);
			});
			map.on('popupopen', function(e){
				var popup = document.querySelectorAll('.leaflet-popup-content');
				var style = e.target._popup._contentNode.style;
				popup.forEach( item => {
					if(e.target._popup._contentNode.getBoundingClientRect().height > 260){
						item.classList.add('custom-scrollbar');
					}
				});
				
				// TO-DO : Animate scrollbar
				//e.target._popup._contentNode.addEventListener('mouseenter', function (e){
					//e.target._popup._contentNode.classList.add('.custom-scrollbar:hover');
				//});
				
				style = "width: "+style.width+";"+"max-height: 260px;";
				e.target._popup._contentNode.style = style;
				map.panTo(new L.LatLng(e.popup._latlng.lat+e.popup._latlng.lat*0.00001,e.popup._latlng.lng));
			});
			
			function loadmarkers(map){
				// Clean previous geodata
				geodata = [];
				this.geoJSON.remove();
				// Local grouping variables
				hashes = [];
				geojs = [];
				var ghash;
				// Grouping points by GHASH
				geopoints.forEach( point => {
					ghash = encodeGeoHash(point.geometry.coordinates[0], point.geometry.coordinates[1]);
					ghash = ghash.substr(0,ZOOMLEVELS[map.getZoom()]);
					if(!(ghash in hashes)){
						hashes[ghash] = [{
							name: point.properties.name,
							popup: point.properties.popupContent,
							lat: point.geometry.coordinates[1],
							long: point.geometry.coordinates[0]
						}];
					}else {
						hashes[ghash].push({
							name: point.properties.name,
							popup: point.properties.popupContent,
							lat: point.geometry.coordinates[1],
							long: point.geometry.coordinates[0]
						});
					}
				});
				
				// Setting new GeoJson data to display on zoom end
				for(hash in hashes){
					var len = hashes[hash].length;
					var bindpopup = '';
					var avg_lat = 0;
					var avg_long = 0;
					for(point of hashes[hash]){
						if(len > 1){
							avg_lat += parseFloat(point.lat) - 0.00013168;
							avg_long += parseFloat(point.long ) + 0.00011691;
							//doesn't show span info in groupal point
							bindpopup += point.popup.substr(0,point.popup.search("<span"));
						}else{
							avg_lat = parseFloat(point.lat) - 0.00013168;
							avg_long = parseFloat(point.long) + 0.00011691;
							bindpopup += point.popup;
						}
					}
					var tpoint = {
						"type": "Feature",
						"properties": {
							"name": ((len > 1 ) ? hash : hashes[hash].name),
							"popupContent": bindpopup
						},
						"geometry": {
							"type": "Point",
							"coordinates": [String((avg_long/len).toFixed(8)),String((avg_lat/len).toFixed(8))]
						}
					};
					let tgeopoint = L.geoJSON(tpoint, {
						style: osm_style,
						onEachFeature: osm_onEachFeature,
						pointToLayer: function (feature, latlng) {
							return new L.CircleMarker(latlng, {
								radius: ((len < 2) ? 8 : len*1.2 + 8) ,
								fillColor: hsl_col_perc(hashes[hash].length*100/geopoints.length),
								color: "#000",
								weight: 1,
								opacity: 0.6,
								fillOpacity: 0.6
							});
						}
					}).addTo(map);
					geojs.push(tgeopoint);
				}
			}
			
			function addlayers(map, folders){
				var layers = {};
				folders.forEach(folder => {
					var layer = L.tileLayer('./app/tools/locations/'+folder+'/{z}/{x}/{y}.png', geoJSONOptions);
					if(!(layer in layers)){
						layers[folder] = layer;
					}else{
						layers[folder].push(layer);
					}
				});
				L.control.layers(baseMaps, layers).addTo(map);
				var overlayers = document.getElementsByClassName("leaflet-control-layers-overlays");
				overlayers = Array.prototype.slice.call(overlayers);
				var i = 0;
				folders.forEach(folder => {
					var slider_input = '<div><input style="width:100px" id="slider_'+folder+'" type="range" min="0" max="1" step="0.1" value="1">';
					var index_selector = '<div style="color: black !important">Z-Index: <input style="margin-top:5px;margin-left:15px;width:35px; border-radius: 6px;" id="zindex_'+folder+'" class="quantity" min="0" name="quantity" value="'+layers[folder].options.zIndex+'" type="number"/></div></div>';
					overlayers[0].childNodes[i].childNodes[0].appendChild(document.createRange().createContextualFragment(slider_input+index_selector));
					var slider = document.getElementById('slider_'+folder+'');
					slider.addEventListener('input', function (e){
						layers[folder].setOpacity(slider.value);
					});
					var index_input = document.getElementById('zindex_'+folder+'');
					index_input.addEventListener('input', function (e){
						layers[folder].setZIndex(index_input.value);
					});
					i++;
				});
			}
			
			function mapInitTitleLayers(map){
				var osm = new L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', layerOptions).addTo(map);
				var opentopomap = L.tileLayer('https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png', {
					maxZoom: 17,
					attribution: 'Map data: &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors, <a href="http://viewfinderpanoramas.org">SRTM</a> | Map style: &copy; <a href="https://opentopomap.org">OpenTopoMap</a> (<a href="https://creativecommons.org/licenses/by-sa/3.0/">CC-BY-SA</a>)'
				});//.addTo(map);
				baseMaps = { "OSM": osm, "Topo": opentopomap };
			}
			
			function hsl_col_perc(percent, start = 40, end = 0) {
				var a = percent / 100,
					b = (end - start) * a,
					c = b + start;

				// Return a CSS HSL string
				return hslToHex(c);
				//return 'hsl('+c+', 100%, 50%)';
			}
			function hslToHex(h,s=100,l=50){
				l /= 100;
				const a = s * Math.min(l, 1 - l) / 100;
				const f = n => {
					const k = (n + h / 30) % 12;
					const color = l - a * Math.max(Math.min(k - 3, 9 - k, 1), -1);
					return Math.round(255 * color).toString(16).padStart(2, '0');   // convert to Hex and prefix "0" if needed
				};
				return `#${f(0)}${f(8)}${f(4)}`;
			}
        </script>
        <?php
    }

    ################### Geocoding functions

    /**
     * Generate binary sha256 of address string. Ignore whitespace & case.
     *
     * @param   string $address
     * @return  string
     */
    private function hash_from_address ($address) {
        $address_min = preg_replace('#\s+#', ' ', mb_strtolower($address));
        $hash = openssl_digest(trim($address_min), 'sha256', true);

        if (!is_string($hash) || strlen($hash) != 32) {
            throw new Exception(_('openssl_digest failure'));
        }

        return $hash;
    }

    /**
     * Search for cached results
     *
     * @param   string  $address
     * @return  StdClass|false
     */
    private function search_geo_cache ($address) {
        $hash = $this->hash_from_address($address);
        $cached_result = $this->Database->getObjectQuery('SELECT * FROM nominatim_cache WHERE sha256=?;', [$hash]);

        return is_object($cached_result) ? $cached_result : false;
    }

    /**
     * Store results in cache.
     *
     * @param   string  $address
     * @param   string  $json
     * @return  void
     */
    private function update_geo_cache ($address, $json) {
        if (!is_string($address) || !is_string($json)) {
            return;
        }

        $values = ['sha256' => $this->hash_from_address($address),
                   'query' => $address,
                   'lat_lng' => $json];

        $this->Database->insertObject('nominatim_cache', $values);
    }

    /**
     * Perform Geocoding lookup
     *
     * @param   string  $address
     * @return  array
     */
    public function get_latlng_from_address ($address) {
        $results = ['lat' => null, 'lng' => null, 'error' => null];

        if (!is_string($address) || strlen($address) == 0) {
            $results['error'] = _('invalid address');
            return $results;
        }

        try {
            // Obtain exclusive MySQL row lock
            $Lock = new LockForUpdate($this->Database, 'nominatim', 1);

            $elapsed = -microtime(true);

            $cached_result = $this->search_geo_cache($address);
            if ($cached_result) {
                $json = json_decode($cached_result->lat_lng, true);
                if (is_array($json)) {
                    return $json;
                }
            }

            $url = $Lock->locked_row->url;
            $url = $url."?format=json&q=".rawurlencode($address);
            $headers = ['User-Agent: phpIPAM/'.VERSION_VISIBLE.' (Open source IP address management)',
                        'Referer: '.$this->createURL().create_link()];

            // fetch geocoding data with proxy settings from config.php
            $lookup = $this->curl_fetch_url($url, $headers);

            if ($lookup['result_code'] != 200) {
                throw new Exception($lookup['error_msg']);
            }

            $geo = json_decode($lookup['result'], true);

            if (!is_array($geo)) {
                throw new Exception(_('Invalid json response from nominatim'));
            }

            if (isset($geo['0']['lat']) && isset($geo['0']['lon'])) {
                $results['lat'] = $geo['0']['lat'];
                $results['lng'] = $geo['0']['lon'];
            }

            $this->update_geo_cache($address, json_encode($results));

        } catch (Exception $e) {
            $results = ['lat' => null, 'lng' => null, 'error' => $e->getMessage()];
        }

        // Ensure we hold the exclusive database lock for a minimum of 1 second
        // (< 1 requests/s across all load-balanced instances of this app)
        $elapsed += microtime(true);
        if ($elapsed < 0) {
            time_nanosleep(0, 1000000000);
        } elseif ($elapsed < 1) {
            time_nanosleep(0, 1000000000*(1 - $elapsed));
        }

        return $results;
    }

}
