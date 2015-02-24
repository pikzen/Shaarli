<?php

class ThumbnailExtractor {
	var $enable_surface_checking = false;
    var $minimum_score = 0.5;
	function retrieve_dom($file) {
		$dom = new DOMDocument;
		// Ewwwwwwww
		// This prevents $dom from throwing a bunch of errors and warnings if the page is malformed
		// (and it will)
		// I'd love to use something less dirty, but there doesn't seem to be one (aside from a custom
		// error handler)
		libxml_use_internal_errors(true);
		$dom->loadHTML($file);
		
		return $dom;
	}

    function get_url($url) {
        // Setup our UA string
        $context = stream_context_create(array(
            'http' => array(
                'method' => 'GET',
                'header' => 'User-Agent: Shaarli/Thumbnailer - http://github.com/pikzen/Shaarli'
            ),
            'ssl' => array(
                "verify_peer" => true,
                "allow_self_signed" => true
            )
        ));

        // Check the mimetype before attempting to parse it.
        // If it's anything other than text/html, attempt to send back the resource directly.
        // Once again,
        $response = file_get_contents($url, false, $context);
        $file_info = new finfo(FILEINFO_MIME_TYPE);
        $mime_type = $file_info->buffer($response);
        $is_compressed = false;
        // Check for gzip compression and dezip it if needed
        // Set the compressed flag to prevent interpreting it as
        // a direct link
        if ($mime_type === "application/octet-stream" ||
            $mime_type === "application/x-gzip") {
            $response = gzdecode($response);
            $is_compressed = true;
        }

        return array($response, $mime_type, $is_compressed);
    }

	function extract_images_yt($url) {
        $files = array();

		$image = new stdClass;
		$regex = "/v=(.*)\&*.*|v\/(.*)/";
		if (preg_match($regex, $url, $matches))
		{
			$image->url = "https://i.ytimg.com/vi/" . $matches[1] . "/default.jpg";
			$image->surface = 10800; // Youtube uses a constant size for its thumbnails. Not like we care anyways
			$image->rankings = "This is a youtube link for 1 point";
			$files[$image->url] = $image;
		}

		return $files;
	}

    /**
     * Generic thumbnail extractor, works for any website
     *
     * Returns early if the content is not text/html (or gzipped html).
     * Attempts to extract the thumbnails from metatags first and exits early if it can.
     * If no metatags can help, parses each image.
     * If width and height attributes are present on an image, use them to calculate its surface.
     * If $enable_surface_checking is set to true, it will request every single image and get its
     * width & height to calculate its surface. This makes the whole process AWFULLY SLOW
     * @param $url URL to pull
     * @return array Hashmap representing each image with its url, surface and other data
     */
	function extract_images($url) {
        $files = array();

        // We've covered the case where the data might be compressed.
        // All that's left is direct linking to a resource.
        if ($mime_type !== "text/html" && !$is_compressed) {
			$item = new stdClass;
			$item->url = $url;
			$item->score = strpos($mime_type, 'image') === 0 ? 1 : 0; 
			// If it's anything but an image, assign it a zero
			// score so we don't accidentally try to render it or something.
			$item->rankings = "This is a direct link. glhf.";
			$item->surface = 0;
			$item->mimetype = $mime_type;

			$files[$url] = $item;
			return $files;
		}

		$dom = $this->retrieve_dom($response);
        // First, try to make use of the OpenGraph API to find the image
        // and use metatags in general
        // Who knows, maybe some people use them.
        $metas = $dom->getElementsByTagName('meta');
        foreach($metas as $meta) {
            if ($meta->getAttribute('property') === "og:image") {
                $item = new stdClass;
                $item->url =  $meta->getAttribute('content');
                $item->score = 1;
                $item->surface = 0;
                $item->rankings = "Found via opengraph metatags";
                $files[$item->url] = $item;

                return $files;
            }
        }

        $images = $dom->getElementsByTagName('img');
		foreach ($images as $img) {
			$item = new stdClass; // let's abuse the fact that PHP does not give a crap about undefined members.
			$item->mimetype = $mime_type;
			$src_attr = $img->getAttribute('src');
			$file_url = "";

			// Check if the address contains an http(s):// part. In case it's not stored locally.
            // Check for relative and others
			if (strpos($src_attr, "http") === 0)
				$file_url = $src_attr;
			else if (strpos($src_attr, "//") === 0) // Yeah, some sites do that.
				$file_url = "http:" . $src_attr;
            else if (strpos($src_attr, "/") === 0) // and absolute positioning too.
                $file_url = $url . substr($src_attr, 1, strlen($src_attr));
			else
				$file_url = $url . $src_attr;

			// Let's not parse it if we have already retrieved it
			// This is most likely to happen with small icons, repeated all over the page
			if (!isset($files[$file_url])) {
				$item->url = $file_url;
				$item->rankings = "";
				$item->surface = -1;
                $got_surface_from_attrs = false;

                // Try to retrieve some info from the image tags
                $width_attr = $img->getAttribute('width');
                $height_attr = $img->getAttribute('height');

                if ($width_attr !== "" && $height_attr !== "") {
                    $item->surface = $width_attr * $height_attr;
                    $got_surface_from_attrs = true;
                }

				// Aaaaaaaaaaaaaaah
				// Yes, hiding the errors is an awful thing to do. Let's hope than nothing bad can happen
				// Basically, file_get_contents may fail with a Warning:  getimagesize(): Failed to enable crypto
				// I don't know what might be the cause, because it seems to happen randomly
				// Note: I've tried it out on an awful connection dropping packets like crazy, so this might
				// be the source of the problem.
				if ($this->enable_surface_checking && !$got_surface_from_attrs) {
					list($width, $height) = @getimagesize($file_url);
					$item->surface = $width * $height;
				}
				$files[$file_url] = $item;
			}
		}

		return $files;
	}

	function score_from_url_and_size($files) {
		// Partly stolen from https://github.com/mauricesvay/ImageResolver/blob/master/src/plugins/Webpage.js
		// Basically, certain items have a higher priority than others (we don't really care about 1x1 stuff for
		// example.)
		$rules = array(
				array("pattern" => "/splash/",                   "score" => 1),
                array("pattern" => "/(youtube|ytimg).*default/", "score" => 1),
				array("pattern" => "/(thumbs|thumbnails|thumb)/","score" => 1),
				array("pattern" => "/img.youtube.com/",          "score" => 1),
				array("pattern" => "/(\/large|\/media|\/medias)/",     "score" => 1),
				array("pattern" => "/images/",                   "score" => 1),
				array("pattern" => "/upload/",                   "score" => 1),
				array("pattern" => "/gravatar.com/",             "score" => -1),
				array("pattern" => "/feeds.feedburner.com/",     "score" => -1),
				array("pattern" => "/icon/",                     "score" => -1),
				array("pattern" => "/logo/",                     "score" => -1),
				array("pattern" => "/spinner/",                  "score" => -1),
				array("pattern" => "/loading/",                  "score" => -1),
				array("pattern" => "/pixel/",                    "score" => -1),
				array("pattern" => "/qrcode/",                   "score" => -1),
				array("pattern" => "/1x1/",                      "score" => -2),
				array("pattern" => "/ads/",                      "score" => -2),
				array("pattern" => "/doubleclick/",              "score" => -2)
		);

        $current_image = 0;
		foreach ($files as $key => $value) {
			$score = 0;
            $position_malus = max((log($current_image + 1, 10) / log(count($files) + 1, 10) * -1), -3); // Reduce the score as we go down the page.
            $score += $position_malus;
            $value->rankings .= "\nReduced score by " . $position_malus . " points because of its position in the page.";
			if ($value->surface > 10000) { // Anything over 100x100 is a good candidate
				$surface_points = min(($value->surface / 10000), 3); 
				$score += $surface_points;
				$value->rankings .= "\nHas a surface of " . $value->surface . " for " . $surface_points . ' points.';
				// The larger the image, the better.
				// Let's not make it too important though. We don't want a gigantic image
				// with no relation to the link take precedence, so clamp it
			}

			foreach ($rules as $pattern) {
				if (preg_match($pattern["pattern"], $key)) {
					$score += $pattern["score"];
					$value->rankings .= "\nMatched " . $pattern["pattern"] . " for " . $pattern['score'] . ' points.';
				}
			}

			$files[$key]->score = $score;
            $current_image++;
		}

		usort($files, function($first, $second) {
			if ($first->score == $second->score) return 0;

			// Yup, 1 and -1 are inverted, because we're ordering in descending order
			return ($first->score > $second->score) ? -1 : 1; 
		});

		// Normalize score between 0 and 1
		if (count($files) > 0) {
            $largest = $files[0]->score;
            foreach ($files as $file) {
                if ($file->score > 0) {
                    $file->score /= $largest;
                }
            }
        }
		return $files;
	}

	function extract($url) {
		$rules = array(
			array("pattern" => "/(youtube\.com|youtu\.be)/", "fun" => '\ThumbnailExtractor::extract_images_yt'),
			array("pattern" => "(.*)",                       "fun" => '\ThumbnailExtractor::extract_images')
		);

		foreach ($rules as $rule) {
			if (preg_match($rule["pattern"], $url))
				return call_user_func($rule['fun'], $url);
		}

        throw new LogicException('Could not find an appropriate thumbnailer to run.');
	}
}
$start = microtime(true);

$thumb      = new ThumbnailExtractor;
$thumb->enable_surface_checking = isset($_GET['surface']);
$thumbnails = $thumb->extract($_GET['url']);
$score      = $thumb->score_from_url_and_size($thumbnails);
echo "<img src=" . $score[0]->url . ">";
echo "<pre>";
var_dump($score);
echo "</pre>";

$end = microtime(true);
echo "Time spent: " . ($end - $start) . ' seconds (' . $start . ' --> ' .$end .')';