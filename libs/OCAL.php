<?php
/**
 *  This file is part of Open Clipart Library <http://openclipart.org>
 *
 *  Open Clipart Library is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  Open Clipart Library is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with Open Clipart Library; if not, write to the Free Software
 *  Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 *
 *  author: Jakub Jankiewicz <http://jcubic.pl>
 */

require_once('System.php');

// Main class extend System to OCAL specific functions
class OCAL extends System {

    private $shutterstock_api_url = "http://api.shutterstock.com/images/search.json?all=0&page_number=1&category_id=29";
    private $shutterstock_api_login = "openclipart";
    private $shutterstock_api_key = "75f6916e802d2969ce255ad03f0316a817535922";

    function __construct($settings) {
        $config = json_decode(file_get_contents('config.json'), true);
        $protocol = (isset($_SERVER['HTTPS'])) ? 'https' : 'http';
        $config['root'] = $protocol . '://' . $_SERVER['HTTP_HOST'];
        $config['root_directory'] = $_SERVER['DOCUMENT_ROOT'];
        System::__construct(array_merge($config, $settings));
    }
    function nsfw() {
        return $this->config->get('nsfw', true);
        if ($this->is_logged()) {
            return $this->config->get('nsfw', $this->nfsw);
        } else {
            return true;
        }
    }
    function favorite($clipart) {
        if (!$this->is_logged()) {
            throw new Exception("You can't favorite a clipart if you are not logged in");
        }
    }
    function create_thumbs($where, $order_by) {
         if ($this->nsfw()) {
            $nsfw = "AND openclipart_clipart.id not in (SELECT clipart FROM openclipart_clipart_tags INNER JOIN openclipart_tags ON tag = openclipart_tags.id WHERE name = 'nsfw')";
        } else {
            $nsfw = '';
        }
        if ($this->is_logged()) {
            $fav_check = $this->get_user_id() . ' in '.
                '(SELECT user FROM openclipart_favorites'.
                ' WHERE openclipart_clipart.id = clipart)';
        } else {
            $fav_check = '0';
        }
        if ($where != '' && $where != null) {
            $where = "AND $where";
        }
        $query = "SELECT openclipart_clipart.id, title, filename, link, created, username, count(DISTINCT user) as num_favorites, created, date, $fav_check as user_favm, downloads FROM openclipart_clipart INNER JOIN openclipart_favorites ON clipart = openclipart_clipart.id INNER JOIN openclipart_users ON openclipart_users.id = owner WHERE openclipart_clipart.id NOT IN (SELECT clipart FROM openclipart_clipart_tags INNER JOIN openclipart_tags ON openclipart_tags.id = tag WHERE clipart = openclipart_clipart.id AND openclipart_tags.name = 'pd_issue') $nsfw $where GROUP BY openclipart_clipart.id ORDER BY $order_by DESC LIMIT " . $this->config->home_page_thumbs_limit;
        $clipart_list = array();
        foreach ($this->db->get_array($query) as $row) {
            $filename_png = preg_replace("/.svg$/",
                                         ".png",
                                         $row['filename']);
            $human_date = human_date($row['created']);
            $data = array(
                'filename_png' => $filename_png,
                'human_date' => $human_date
                //TODO: check when close this query
                //'user_fav' => false
            );
            $clipart_list[] = array_merge($row, $data);
        }
        return array('cliparts' => $clipart_list);
    }
    
    function shutterstock_json($terms = null) {
        $auth_code = base64_encode($this->shutterstock_api_login . ":" .
                                   $this->shutterstock_api_key);
        $headers = array();
		$headers[] = "Authorization: Basic $auth_code";

		$terms = trim($terms);
		$terms = preg_replace('/\s+/','+',$terms);
        $url = $this->shutterstock_api_url;
        if ($terms != null) {
            $url .= '&searchterm='. $terms;
        }
        $ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

		$resp = curl_exec($ch);
		$response_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ( $response_code != '200' ) {
            return null;
        } else {
            return json_decode($resp);
        }
    }
    function shutterstock($terms = null) {
        $shutter = $this->shutterstock_json($terms);
        if (!$shutter || $shutter->count == 0) {
            $shutter = $this->shutterstock_json();
        }
        if ($shutter->count > 6) {
            return array_slice($shutter->results, 0, 6);
        } else {
            return array();
        }
    }
    function tag_counts($tags) {
        $db = $this->db;
        $tags = implode(", ", array_map(function($tag) use ($db) {
            return "'". $db->escape($tag) . "'";
        }, $tags));
        $query = "select name, count(name) as count from openclipart_clipart_tags inner join openclipart_tags on tag = id where name in ($tags) group by name order by count desc";
        return $db->get_array($query);
    }
}