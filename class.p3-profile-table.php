<?php
/**
 * Lists the performance profiles
 *
 * @author Kurt Payne, GoDaddy.com
 * @version 1.0
 * @package P3_Profiler
 */
class p3_profile_table extends WP_List_Table {

	/**************************************************************************/
	/**        SETUP                                                         **/
	/**************************************************************************/

	/**
	 * Constructor
	 * @return p3_profile_table
	 */
	public function __construct() {
		parent::__construct(array(
			'singular'  => 'scan',
			'plural'    => 'scans'
		));
	}

	/**
	 * Set up the columns, dataset, paginator
	 * @return void
	 */
    public function prepare_items() {

		// Set up columns
        $columns = $this->get_columns();
        $hidden = array();
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = array($columns, $hidden, $sortable);

		// Perform bulk actions
		$this->do_bulk_action();
        $data = $this->_get_profiles();

		// Sort data
		$orderby = (!empty($_REQUEST['orderby'])) ? $_REQUEST['orderby'] : 'name';
		$order = (!empty($_REQUEST['order'])) ? $_REQUEST['order'] : 'asc';
		$data = $this->_sort($data, $orderby, $order);

		// 20 items per page
		$per_page = 20;

		// Get page number
		$current_page = $this->get_pagenum();

		// Get total items
        $total_items = count($data);
		
		// Carve out only the visible dataset
        $data = array_slice($data, $current_page-1 * $per_page, $per_page);
        $this->items = $data;

		// Set up the paginator
        $this->set_pagination_args(array(
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page)
        ));
    }

	/**************************************************************************/
	/**        COLUMN PREP                                                   **/
	/**************************************************************************/
	
	/**
	 * If there's no column_[whatever] method available, use this to render
	 * the column
	 * @param array $item
	 * @param string $column_name
	 * @return string
	 */
    public function column_default($item, $column_name) {
		switch ($column_name) {
			case 'name' :
			case 'date' :
			case 'count' :
			case 'filesize' :
				return $item[$column_name];
				break;
			default:
				return '';
		}
	}

	/**
	 * Render the "title" column
	 * @param array $item
	 * @return string 
	 */
    public function column_title($item) {
        $actions = array(
            'delete'    => sprintf('<a href="?page=%s&action=%s&name=%s">Delete</a>', $_REQUEST['name'], 'delete', $item['name']),
        );

        //Return the title contents
        return sprintf('%1$s <span style="color:silver">(id:%2$s)</span>%3$s',
            $item['name'],
            $item['name'],
            $this->row_actions($actions)
        );
    }

	/**
	 * Render the checkbox column
	 * @param type $item
	 * @return string
	 */
    public function column_cb($item) {
        return sprintf(
            '<input type="checkbox" name="%1$s[]" value="%2$s" />',
            $this->_args['singular'],
            $item['filename']
        );
    }

	/**
	 * Get a list of columns
	 * @return array
	 */
    public function get_columns() {
        $columns = array(
            'cb'       => '<input type="checkbox" />',
            'name'     => 'Name',
            'date'     => 'Date',
            'count'    => 'Visits',
			'filesize' => 'Size'
        );
        return $columns;
    }

	/**
	 * Get a list of sortable columns (note, do not return the checkbox column)
	 * @return array 
	 */
	public function get_sortable_columns() {
        $sortable_columns = array(
            'name'     => array('name', true),
            'date'     => array('date', true),
            'count'    => array('count', true),
			'filesize' => array('filesize', true)
        );
        return $sortable_columns;
    }

	/**
	 * Add some the "view" and "delete" links to the scan 
	 * @param string $key Internal key (scan filename)
	 * @param string $display Display key (scan filename)
	 * @return string
	 */
	private function _action_links($key, $display) {
		$url = add_query_arg(array(
			'p3_action' => 'view-scan',
			'name' => $key
		));
		return <<<EOD
<a href="$url"><strong>$display</strong></a>
<div class="row-actions-visible">
	<span class="view">
		<a href="$url" data-name="$key" title="View the results of this scan" class="view-results">View</a> |
	</span>
	<span>
		<a href="javascript:;" data-name="$key" title="Continue this scan" class="p3-continue-scan">Continue</a> |
	</span>
	<span class="delete">
		<a href="javascript:;" data-name="$key" title="Delete this scan" class="delete-scan delete">Delete</a>
	</span>
</div>
EOD;
	}
	
	/**************************************************************************/
	/**        BULK ACTIONS                                                  **/
	/**************************************************************************/
	
	/**
	 * Get a list of which actions are available in the bulk actions dropdown
	 * @return string 
	 */
    public function get_bulk_actions() {
        $actions = array(
            'delete'    => 'Delete'
        );
        return $actions;
    }

	/**
	 * Performan any bulk actions
	 * @return void
	 */
    public function do_bulk_action() {
		global $p3_profiler_plugin;
        if ('delete' === $this->current_action() && !empty($_REQUEST['scan'])) {
			if (!wp_verify_nonce($_REQUEST['p3_nonce'], 'delete_scans'))
				wp_die('Invalid nonce');
			foreach ($_REQUEST['scan'] as $scan) {
				$file = P3_PROFILES_PATH  . DIRECTORY_SEPARATOR . basename($scan);
				if (!file_exists($file) || !is_writable($file) || !unlink($file)) {
					wp_die('Error removing file ' . $file);
				}
			}
			$count = count($_REQUEST['scan']);
			if ($count == 1) {
				$p3_profiler_plugin->add_notice("Deleted $count scan.");
			} else {
				$p3_profiler_plugin->add_notice("Deleted $count scans.");
			}
		}
    }

	/**************************************************************************/
	/**        DATA PREP                                                     **/
	/**************************************************************************/

	/**
	 * Sort the data
	 * @param array $data
	 * @param string $field Field name (e.g. 'name' or 'count')
	 * @param string $direction asc / desc
	 * @return array
	 */
	private function _sort($data, $field, $direction) {

		// Override the count / date fields as they've had some display markup
		// applied to them and need to be sorted on the original values
		switch ($field) {
			case 'count' :
				$field = '_count';
				break;
			case 'date' :
				$field = '_date';
				break;
			case 'filesize' :
				$field = '_filesize';
				break;
		}
		$sorter = new p3_profile_table_sorter($data, $field);
		return $sorter->sort($direction);
	}

	/**
	 * Get a list of the profiles in the profiles folder
	 * Profiles are named as "*.json".  Add additional info, too, like
	 * date and number of visits in the file
	 * @uses list_files
	 * @return type 
	 */
	private function _get_profiles() {
		$p3_profile_dir = P3_PROFILES_PATH;
		$files = list_files($p3_profile_dir);
		$files = array_filter($files, array(&$this, '_filter_json_files'));
		$ret = array();
		foreach ($files as $file) {
			$time = filemtime($file);
			$count = count(file($file));
			$key = basename($file);
			$name = substr($key, 0, -5); // strip off .json
			$ret[] = array(
				'filename'  => basename($file),
				'name'      => $this->_action_links($key, $name),
				'date'      => date('D, M jS', $time) . ' at ' . date('g:i a', $time),
				'count'     => number_format($count),
				'filesize'  => $GLOBALS['p3_profiler_plugin']->readable_size(filesize($file)),
				'_filesize' => filesize($file),
				'_date'     => $time,
				'_count'    => $count
			);
		}
		return $ret;
	}

	/**
	 * Only let "*.json" files pass through
	 * @param type $file
	 * @return type 
	 */
	private function _filter_json_files($file) {
		return ('.json' == substr(strtolower($file), -5));
	}
}