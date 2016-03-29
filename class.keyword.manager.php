<?php
class Keyword_Manager {
	/**
	 * Constructor
	 *
	 * @return void
	 * @author Henry Luong
	 */
	const keyword_slug = 'keyword-manager';

	
	public function __construct() {
		createAlchemyDataSettings();
		add_action( 'admin_menu', array(__CLASS__, 'addKeywordManagerMenu') );
		add_filter('post_row_actions', array(__CLASS__,'alchemy_redirect'), 10, 2);
		add_filter('page_row_actions', array(__CLASS__,'alchemy_redirect'), 10, 2);
		add_action( 'admin_enqueue_scripts', array(__CLASS__,'add_ajax_javascript_file'));
		add_action( 'wp_ajax_process_batch', array(__CLASS__, 'ajax_process_batch'));
	}

	/**
	* Add ajax javascript file
	*/
	public static function add_ajax_javascript_file(){
		wp_enqueue_script( 'ajax_batch_processing_script', plugin_dir_url( __FILE__ ) . 'javascript/batch_process.js', array('jquery') );
	}
	
	
	/**
	* Add a management page to display alchemy keywords results.
	*/
	public static function addKeywordManagerMenu(){
		add_management_page( __('Alchemy Keyword Processing', 'advanced-taxonomy'), __('Alchemy Keyword Processing', 'advanced-taxonomy'), 'edit_posts', self::keyword_slug, array( __CLASS__, 'processMainPage' ) );
	}
	/**
	* Caller function of addKeywordManagerMenu.
	* This function displays the results and all associated $_POST actions.
	*/
	public static function processMainPage(){
		
		// do_action is a debugging funciton in the add_debug_info plugin schemas
		do_action( 'add_debug_info', $_POST, '_POST' );
		do_action( 'add_debug_info', $_GET, '_GET');
		do_action( 'add_debug_info', $_REQUEST, '_REQUEST');
		
		
		if(isset($_GET['postID'])){
			if(false === get_post_status($_GET['postID'])){
					wp_die("INVALID POST ID!");
				}
		}
		
		if(!isset($_GET['mode'])){
			return self::displayIntroPage();
		}
		switch ($_GET['mode']){
			case 'individual':
				// display individual mode default page with post view
				if(!isset($_GET['postID'])){
					wp_die("POSTID EXPECTED AS ONE OF THE PARAMETERS!");
				}
				$postid = $_GET['postID'];
				$post = get_post($postid);
				self::attachPostView($postid);
				self::attachKeywordConfirmationView();
				break;
			
			case 'query-confirmed':
				// allow query and extracted keywords to be displayed.
				if(!isset($_GET['postID'])){
					wp_die("POSTID EXPECTED AS ONE OF THE PARAMETERS!");
				}
				$postid = $_GET['postID'];
				self::attachPostView($postid);
				$keywords = self::query_Alchemy($postid);
				self::attachKeywordForms($keywords);
				break;
			
			case 'query-results':
				// show individual mode keywords processing results
				if(!isset($_GET['postID'])){
					wp_die("POSTID EXPECTED AS ONE OF THE PARAMETERS!");
				}
				echo '<div class="wrap">' . '</div>';
				
				$status = self::processSelectedKeywords();
				$message = "";
				if($status == true or $status == 1){
					$message = '<h1 align="center" style="color:green;"> All Selected Keywords have been saved! </h1>';
				}
				else{
					$message = '<h1 align="center" style="color:red;"> A Problem occured while processing the selected keywords! </h1>';
				}
				echo $message;
				$postid = $_GET['postID'];
				$post = get_post($postid);
				self::attachPostView($postid);
				break;
			
			case 'batch-process':
				// display forms so that users can insert parameters
				echo '<h2 align="center"> KEYWORDS BATCH PROCESSING </h2>';
				self::attachBatchProcessForms();
				
				break;
				
			case 'query-batch-process':
				// do the post data checking and keywords processing here
				$setting = self::saveSelectedBatchObject();
				
				$postids = get_all_post_ids();
				$pageids = get_all_page_ids();
				$taxonomies = $setting['taxonomies'];
				$postcutoff = $setting['postcutoff'];
				$pagecutoff = $setting['pagecutoff'];
				$target = $setting['target'];
				switch($target){
					case 'all':
						break;
					case 'post':
						$pageids = array();
						break;
					case 'page':
						$postids = array();
						break;
					default:
						$postids = array();
						$pageids = array();
						break;
				}
				// Filter only relevant post and page ids to add (only articles that haven't been added to any of the taxonomies);
				$postids = self::filter_untaxed_docs($postids, $taxonomies);
				$pageids = self::filter_untaxed_docs($pageids, $taxonomies);
				$total = count($postids) + count($pageids);
				echo '<h3> The requested batch processing affects <u>'. $total .' documents </u>' . 'and requires a total of  <u>'. $total . '</u> api calls </h3>';
				echo '<div class="batch-confirmed" style="color:red;">
				Do you want to continue? 
				       <p class="submit" style="padding:2 2 2em;">	
						<input type="button" style="background-color: green;height:50px;width:200px;" class="button-primary" value="Yes! Perform Batch Processing!" />
					   </p>
					  </div>';
					
				echo '<div id="batch_response" style="height:70%;width:95%; background-color:white; border:5px solid black; font:18px/26px;"> </div>';
				
				echo '<div class="batch-cancel">
						<p class="submit" style="padding:2 2 2em;">
							<input id="batch-cancel" type="button" style="background-color:red;height:50px;width:200px;" class="button-primary" value="Cancel Batch Processing!"/>
						</p>
					  </div>';
				$img = plugins_url('advanced-taxonomy/icon/hourglass.gif');
				echo '<div id="spiningImg"> 
							<img src="' . $img . '"/>
							Processing ...
					  </div>';
				break;
	
				
			case 'batch-process-results':
				echo '<div id="batch_response" style="height:70%;width:95%; background-color:white; border:5px dash black; font:18px/26px;">';
				echo '</div>';
				// displaying batch processing status here.
				echo '<h2> BATCH PROCESS DONE! </h2>';
				break;
				
			case 'batch-process-error':
				echo '<h2> SOMETHING WENT WRONG!!!!!!! </h2>';
				echo '<div id="batch_response" style="height:70%;width:95%; background-color:white; border:5px dash black; font:18px/26px;">';
				echo '</div>';
				break;
			default:
				wp_die("INVALID KEYWORDS PROCESSING MODE!");
		}
		
	}
	
	/** filter out taxonomized docids given a list of docids and the taxonomies that they might have a term in
	* @param: $docids - list of document ids
	* 		  $taxonomies - list of taxonomy object containing keys 'name' and 'label'
	* @return: list of filtered docids.
	*/
	public static function filter_untaxed_docs($docids, $taxonomies){
		// Filter only relevant post and page ids to add (only articles that haven't been added to any of the taxonomies);
		foreach($taxonomies as $taxonomy){
			$taxname = $taxonomy['name'];
			foreach ($docids as $i => $id){
				 if(is_object_in_term( $id, $taxname)){
					unset($docids[$i]);
				}
			}
		}
		return $docids;
	}
	/** Display introduction page on Keyword Batch processing
	 * @return boolean
	 * @author Henry Luong
	 */	
	public static function displayIntroPage(){
		echo '<div class="wrap">';
		get_screen_icon();
		echo '<h2 align="center">';  
		echo '</h2>';
		
		echo '<div style="height:70%;width:95%; background-color:white; border:5px solid black; font:18px/26px Georgia, Garamond, Serif; overflow:auto ;">';
		
		echo '<h2 align="center"> Welcome to Alchemy Keyword Processing Page! </h2>';
		echo '<ul>  
				<li> At this page, you will have the options to select which mode of 
					to extracts keywords from your POSTs or PAGEs and whether to categorize them
					into your own taxonomies. 
				</li>';
				
		echo 	'<li> You will have the following 2 options: </li>';
		echo 	'<ol> 
						<li> <strong> Individual Mode </strong> allows keywords from each post/page to be extracted and applied
							to each selected taxonomy by the user. </li>
							<ul>
								<li> To use this mode, simply goes to "All Posts" or "All Pages" and have
									the mouse hovers over the lists of each article. Simply click "Get Alchemy Keywords" to proceed. 
								</li>
							</ul>'; 
		echo	 		'<li> <strong> Batch Processing Mode </strong> allows keywords from multiple or all posts/pages to be 
								extracted and applied to each selected taxonomy based on a cutoff value based on the relevance of the keywords.
						</li>
							<ul> 
								<li> To use this mode, simply click the button below! </li>
							</ul>
				</ol>
			  </ul>';
		echo '</div>';
		echo '</div>';
		$batch_url = admin_url( 'tools.php?page='.self::keyword_slug . '&mode=batch-process');
		?>
		
			<form name="batch_process" method="post" action="<?php echo $batch_url; ?>">
				<p class="submit" style="padding:2 2 2em;">	
					<input type="submit" style="background-color: blue;height:50px;width:200px;" class="button-primary" value="Batch Process" />
				</p>
			</form>
		
		<?php
		return true;
	}
	
	/** Display the title and the contents of article inside a scroll panel.
	*  @param $postid - the document id to specify
	* @author: Henry Luong
	*/
	public static function attachPostView($postid){
		
		$thepost = get_post($postid);
		echo '<div class="wrap">';
		get_screen_icon();
		echo '<h2 align="center">';  
		_e("Alchemy Keywords Processing", 'advanced-taxonomy'); 
		echo '</h2>';
		
		
		echo '<div style="height:500px;width:900px; background-color:white; border:5px solid black; font:18px/26px Georgia, Garamond, Serif;overflow:auto;">';
		echo '<h2 align="center">' . $thepost->post_title .  '</h2>';
		echo "<h4> " . self::termsPrinter($postid) . "</h4>";
		echo $thepost->post_content;
		echo '</div>';
		echo "<h2> <p> " . self::termsPrinter($postid) . "</h2> </p> ";
		echo '</div>';
		
	}
	
	/** attach keyword confirmation view before querying keywords
	 * @return boolean
	 * @author Henry Luong
	 */	
	public static function attachKeywordConfirmationView(){
		$confirm_url = admin_url( 'tools.php?page='.self::keyword_slug .'&postID='. $_GET['postID'] . '&mode=query-confirmed'); ;
		?>
			<form name="allow-alchemy" method="post" action="<?php echo $confirm_url; ?>">
				<p class="submit" style="padding:2 2 2em;">	
					<input type="submit" style="background-color: green;height:50px;width:200px;" class="button-primary" value="Get Alchemy Keywords!" />
					<div style="color:red; font:26px/26px Georgia, Garamond, Serif; "> <strong> This option requires 1 Alchemy API Call! </strong> </div>
				</p>
			</form>
		<?php	
			
	}
	
	/** Draw/echo keywords selection form for individual mode
	* @author: Henry Luong
	*/
	public static function attachKeywordForms($keywords){
		$postID = $_GET['postID'];
		$apiContent = getApi_Content('alchemy');
		$bobject = getApi_BatchSetting($apiContent);
		$cutoff = .85;
		do_action( 'add_debug_info', $bobject, '_BATCH SETTINGS');
		if(!empty($bobject)){
			$type = get_post_type($postID);
			do_action( 'add_debug_info', $type, '_POST TYPE');
			$cutoff = ($type == 'post')? $bobject['postcutoff'] : $bobject['pagecutoff'];
			
		}
		echo
		'
		<script language="JavaScript">
		function toggle(source) {
		  checkboxes = document.getElementsByClassName("alchemyterm");
		  for(var i=0, n=checkboxes.length;i<n;i++) {
			checkboxes[i].checked = source.checked;
		  }
		}
		</script>
		'; 
		
		echo "<div class='warp'> <table style='width:90%' >
		  <tr >
			<th> <h3> Select </h3> </th>
			<th> <h3> Keyword </h3> </th>
			<th> <h3> Relevance </h3> </th>
		  </tr>
		  <tbody>
		
		" ;
		echo 
		'<tr>
			<td align="center"> <input type="checkbox" onClick="toggle(this)"><strong> SELECT ALL </strong></td>	
		</tr>' ;
		$individual_url = admin_url( 'tools.php?page='.self::keyword_slug .'&postID='. $_GET['postID'] . '&mode=query-results');
		echo "<form name='auto_cat' action='".  $individual_url   ."' method='post'>";
		$i = 0;
		$mark = 0;
		foreach ($keywords as $keyword){
			$text = $keyword['text'];
			$relevance = $keyword['relevance'];
			$boxname = 'term_index#' . $i;
			$textname = 'term#' . $i;
			if($mark >= $cutoff and $relevance <= $cutoff){
				echo  '<tr style="color: #BFBFBF; width: 100%; background: #EEE9E9;"> 
				<td align="left" colspan="3"> QUALIFIED RELEVANCE &#x2191;  </td> </tr>';
			}
			$mark = $relevance;
			echo
			'<tr >
				<td align="center"> 
					<input type="checkbox" class="alchemyterm" name="'. $boxname .'" value="'. $i .'" > 
				</td>
				<td align="center"> 
					<input type="text" name="'. $textname .'" style="font-weight: bold" value= "'. $text . '" 
				</td>
				<td align="center"> ' . $relevance . ' </td>
			</tr>';
			$i++;
			
			
		}
		if($mark >= $cutoff){
			echo  '<tr style="color: #BFBFBF; width: 100%; background: #EEE9E9;"> 
				<td align="left" colspan="3"> QUALIFIED RELEVANCE &#x2191;  </td> </tr>';
		}
		
		
		echo '
		</tbody> </table>';
		self::attachTaxonomyCheckBox();
		echo '<input type="submit" name="alchemy-box" value="Apply Selected Terms">
		</form>
		</div>
		';
	}

	/** Draw/Echo Taxonomy Selection check boxes
	* @author: Henry Luong
	*/
	public static function attachTaxonomyCheckBox(){
		$list = self::getTaxonomyObjects();
		echo
		'<h3 align="left"> <br> Add Selected Term(s) to:  </h3>
		<div class="wrap">
			<script language="JavaScript">
			function flipbox(source) {
			  checkboxes = document.getElementsByClassName("TaxoCheckbox");
			  for(var i=0, n=checkboxes.length;i<n;i++) {
				checkboxes[i].checked = source.checked;
			  }
			}
			</script>
		'; 
		echo 
		'
			<p align="left">
				<input type="checkbox" onClick="flipbox(this)"> <strong> SELECT ALL </strong> 
			</p>	
		' ;
		foreach($list as $item){
			$name = $item['name'];
			$label = $item['label'];
			echo 
			'<p align="left"> 
				<input type="checkbox" class="TaxoCheckbox" name="'. $name. '" value='. "'". $label . "'".'>
					<strong>'. $label . '</strong> 
			</p>';
		}
		echo '
		</div>
		';
	}
	
	/** Draw/Echo html batch process form page
	* @author: Henry Luong
	*/
	public static function attachBatchProcessForms() {
		$settings = getApi_content('alchemy');
		do_action( 'add_debug_info', $settings, '_ALCHEMY SETTINGS');
		$setting = getApi_BatchSetting($settings);
		//$taxonomies = $setting['taxonomies'];
		$postcutoff = (!empty($setting))? $setting['postcutoff'] : .9;
		$pagecutoff = (!empty($setting))? $setting['pagecutoff'] : .9;
		//$target = $setting['target'];
		
		$batch_url = admin_url( 'tools.php?page='.self::keyword_slug.'&mode=query-batch-process');
		echo '<div class="wrap">';
		echo "<form name='batch_process' action='".  $batch_url   ."' method='post'>";
		
		echo ' <p> <label for="post-cutoff"> <strong> Acceptance Keyword Relevance Cutoff for All Posts </strong> </label> </p>';
		echo '<input type="number" name="post-cutoff" step="0.01" min="0" max="1" value="'. $postcutoff .'" required>';
		echo ' <p> <label for="page-cutoff"> <strong> Acceptance Keyword Relevance Cutoff for All Pages </strong> </label> </p>';
		echo '<input type="number" name="page-cutoff" step="0.01" min="0" max="1" value="' . $pagecutoff . '" required>';
		echo ' <p> <label for="batch-target"> <Strong> Please choose a batch option: </strong> </label> </p>';
		echo '
			<select name="batch-target" required >
				<option></option>
				<option value="none">None</option>
				<option value="post">All Posts</option>
				<option value="page">All Pages</option>
				<option value="all">All Documents and Articles</option>
			</select>';
		self::attachTaxonomyCheckBox();
		echo '<input type="submit" name="alchemy-box" value="Apply Batch Processing!">
		</form>
		</div>
		';
	}
	
	
	/** Redirecting action links 'Alchemy Keywords' in each mouse-over to the
	* management page.
	* @author: Henry Luong
	*/
	public static function alchemy_redirect($actions, $post_object)
	{	
		$pageURL = admin_url( 'tools.php?page='.self::keyword_slug );
	   $actions['alchemy-redirect'] = 
	   '<a href="'. $pageURL . '&postID='. $post_object->ID . '&mode=individual' .'"> '
			. __('Get Alchemy Keywords') . 
	   '</a>';
	   //print_r($post_object);
	   return $actions;	
	}
	
	
	/** process term post submitted values and save newly
	* submitted terms to database.
	* @return: true/false
	* @author: Henry Luong
	*/
	public static function processSelectedKeywords() {
		
		if(!isset($_GET['postID'])){
			return false;
		}
		$post_id = $_GET['postID'];
		$keywords = self::getSelectedKeyword();
		$taxonomies = self::getSelectedTaxonomy();
		
		// if neither of the 2 things are selected, what's the point of adding the terms?
		if(empty($taxonomies)){
			return false;
		}
		if(empty($keywords)){
			return false;
		}
	
		foreach($taxonomies as $taxonomy){
			foreach($keywords as $keyword){
				$taxname = $taxonomy['name'];
				$addstatus = self::add_Term( $taxname, $keyword);
				$setstatus = wp_set_object_terms( $post_id, $keyword, $taxname, true );

			}
			
		}
		return true;
	}
	
	/** process Batch by looping over each lits of post ids and query each document
	*  
	* @param: $postids - The document ids of all posts
			  $pageids - The doucment ids of all pages
			  $postcutoff - The cutoff relevance value for posts
			  $pagecutoff - The cutoff relevance value for pages
			  $taxonomies - The list of the taxonomy objects
	* @return: true/false
	* @author: Henry Luong
	*/
	public static function processBatch($postids, $pageids, $postcutoff, $pagecutoff, $taxonomies){
		if(empty($taxonomies)){
			return array('npost' => count($postids), 'npage' => count($pageids), 'status' => false);
		}
		
		foreach($postids as $postid){
			foreach($taxonomies as $taxonomy){
				$keywords = self::query_FilteredKeywords($postid, $postcutoff);
				foreach($keywords as $keyword){
					$taxname = $taxonomy['name'];
					$addstatus = self::add_Term( $taxname, $keyword);
					$setstatus = wp_set_object_terms( $postid, $keyword, $taxname, true );
				}
			}
		}
		
		foreach($pageids as $pageid){
			foreach($taxonomies as $taxonomy){
				$keywords = self::query_FilteredKeywords($pageid, $pagecutoff);
				foreach($keywords as $keyword){
					$taxname = $taxonomy['name'];
					$addstatus = self::add_Term( $taxname, $keyword);
					$setstatus = wp_set_object_terms( $pageid, $keyword, $taxname, true );
				}
			}
		}
		
		$data = array('npost' => count($postids), 'npage' => count($pageids), 'status' => true);
		return $data;
		
	}
	
	/** Query Filtered Keywords
	* @param: $docid - The document id
			  $cutoff - The relevance cutoff value
	* @return: list of accepted keywords
	* @author: Henry Luong
	*/
	public static function query_FilteredKeywords($docid, $cutoff){
		$keywords = self::query_Alchemy($docid);
		$filtered = array();
		foreach($keywords as $keyword){
			$text = $keyword['text'];
			$relevance = $keyword['relevance'];
			if($relevance >= $cutoff){
				$filtered[] = $text;
			}
		}
		return $filtered;
	}
	
	
	
	/** Add and insert new term/keyword into database
	*  
	* @param: $taxonomy - the taxonomy database name
			  $term_name - the word to add
	* @return: the added term's id
	* @author: Henry Luong
	*/
	private static function add_Term( $taxonomy, $term_name, $parent = 0 ) {
		$term_slug = trim($term_name);
		$term_slug = strtolower($term_slug);
		if(empty($term_name) or empty($term_slug))
			return false;
		$id = term_exists($term_name, $taxonomy, $parent);
		if ( is_array($id) )
			$id = (int) $id['term_id'];
		// if term already exist, don't add anything.
		if ( (int) $id != 0 ) {
			return $id;
		}
		
		// Insert on DB
		$term = wp_insert_term( $term_name, $taxonomy, array('description' => 'Added by autocategorization feature.' , 'slug' => $term_slug, 'parent' => $parent) );
			/* write error:
				$myfile = fopen("INSERTTERM.txt", "a");
				$dump = print_r($term, true);
				fwrite($myfile, $dump);
				fclose($myfile);
			*/
		// Cache
		clean_term_cache($parent, $taxonomy);
		clean_term_cache($term['term_id'], $taxonomy);
		
		return $term['term_id'];
	}
	/** Get selected Taxonomy/(ies) from individual html mode. 
	* @return: list of Taxonomy object with keys 'name', 'label'
	* @author: Henry Luong
	*/
	public static function getSelectedTaxonomy(){
		$options = get_option( ATAXO_OPTION );
		$list = array();
		$i = 0;
		foreach($options['taxonomies'] as $key=>$value){
			$label = $value['labels']['name'];
			if(isset($_POST[$key])){
				$object = array('name' => $key, 'label' => $label);
				$list[$i] = $object;
				$i++;
			}
		}
		return $list;
	}
	
	/** Get selected keyword(s) from individual mode checkboxes
	* @return: list of selected keywords
	* @author: Henry Luong
	*/
	public static function getSelectedKeyword() {
		$termlst = array();
		$termprefix = 'term_index#';
		$wordprefix = 'term#';
		$i = 0;
		$j = 0;
		while(isset($_POST[$wordprefix . $i])){
			$keyword = $_POST[$wordprefix . $i];
			if(isset($_POST[$termprefix . $i])){
				$termlst[$j] = $keyword;
				$j++;
			}
			$i++;
		}
		return $termlst;
		
	}
	/** Get selected batching object
	* @return: associative array containing all parameters gathered from batching html form
	* @author: Henry Luong
	*/
	public static function saveSelectedBatchObject(){
		if(empty($_POST['post-cutoff']) or empty($_POST['batch-target'])){
			wp_die("Back button problem!, please resubmitt previous form!");
		}
		
		$taxonomies = self::getSelectedTaxonomy();
		$post_cutoff = $_POST['post-cutoff'];
		$page_cutoff = $_POST['page-cutoff'];
		$target = $_POST['batch-target'];
		$result = array(
			'postcutoff' => $post_cutoff,
			'pagecutoff' => $page_cutoff,
			'target' => $target,
			'taxonomies' => $taxonomies
				);
		setApi_BatchSetting('alchemy', $result);
		return $result;
	}
	
	/** Print html linked terms within an article/document page
	* @param: $postID - Document id
	* @author Henry Luong
	*/
	public static function termsPrinter($postID) {	
		$options = get_option( ATAXO_OPTION );
		$post_term_lst = "";
		foreach ( (array) $options['taxonomies'] as $taxonomy ) {
			$post_term_lst .= get_the_term_list( $postID, $taxonomy['name'], ' | ' , ' | ' , ' | ' );
		}
		return $post_term_lst;
	}
	
	/** Polishing each keyword against noise words and inconsistent/not meaningful texts.
	*  also singlurity vs plurals.
	* @param: $keywords - original keyword object list
	* @return: list of polished keyword objects
	* @author: Henry Luong
	*/
	public static function polishKeyword($keywords){
		
		$pkeywords = $keywords;
		$i = 0;
		foreach ($pkeywords as $keyword){
			$text = $keyword['text'];
			$text = preg_replace('/[^\w\d\s.-]+/', '', $text);
			//$text = Inflect::pluralize($text);
			$text = Inflect::singularize($text);
			$noise_words = array(
			'term','word','terms', 'a', 'of','the', 'and', 'to', 'in', 'i', 'is', 'that', 'it', 'on', 'you', 'this', 'for', 'but', 'with', 'are', 'have', 'be', 'at', 'or', 'as', 'was', 'so', 'if', 'out', 'not'); 
			$regex = "/\b(?<!(-|'))(".implode('|', $noise_words).")(?!(-|'))\b/";
			$text = preg_replace($regex,'',$text);
			$text = ucwords($text);
			$keyword['text'] = $text;
			$pkeywords[$i] = $keyword;
			$i++;
		}
		$result = array_map("unserialize", array_unique(array_map("serialize", $pkeywords)));
		return $result;
	}

	public static function save_alchemy_data($kobject, $docid){
		$mode = isset($GET['mode'])? $GET['mode'] : 0;
		$settingName = '';
		if($mode == 'query-batch-process'){
			$settingName = 'batch';
		}
		elseif($mode == 'query-confirmed'){
			$settingName = 'individual';
		}
		else return;
		$entries = array();
		foreach($kobject as $keyword){
			$entry = array(
				"keyword" => $keyword['text'],
				"relevance" => $keyword['relevance'],
				"docids" => array($docid)
			);
			$entries[] = $entry;
		}
		insert_data_entries($settingName, $entries);
	}
	
	
	/** Query Alchemy Keywords
	*  
	* @param: $docid - The document id to get the contents to query with Alchemy.
	* @return: list of polished keyword objects
	* @author: Henry Luong
	*/
	public static function query_Alchemy($docid){
		$post = get_post($docid);
		$html_content = $post->post_title . $post->post_content;
		$api_Content = getApi_content('alchemy');
		if($api_Content == false){
			add_settings_error('advanced-taxonomy', 'settings_updated', __('Database content for Alchemy setting does not exist or has been corrupted!', 'advanced-taxonomy'), 'error');
			wp_die(__( "Operations terminated due to system error!", 'advanced-taxonomy' ));
		}
		$currentKey = getApi_key($api_Content);
		$alchemyAdapter = new AlchemyAPI($currentKey);
		$response = $alchemyAdapter->keywords('html', $html_content, array('keywordExtractMode' => 'strict' ));
		setApi_incCounter('alchemy');
		if($response['status'] == 'OK') {
			self::save_alchemy_data($response['keywords'], $docid);
			$pkeywords = self::polishKeyword($response['keywords']);
			return $pkeywords;
		}
		else{
			return array();
		}
	}
	/** Eco/draw html keywords chart given a set of keywords .
	* @param: $keywords - list of keyword object with keys 'text' and 'relevance'
	* @return: none
	* @author: Henry Luong
	*/
	public static function drawChart($keywords){
		$postID = $_GET['postID'];
		$apiContent = getApi_Content('alchemy');
		$bobject = getApi_BatchSetting($apiContent);
		$cutoff = .85;
		if(!empty($bobject)){
			$cutoff = is_single($postID)? $bobject['postcutoff'] : $bobject['pagecutoff'];
		}
		$results = 
		"<div class='wrap'> <table style='width:90%' >
		  <tr >
			<th> Keyword </th>
			<th> Relevance </th>
		  </tr>
		  <tbody>
		";
		foreach ($keywords as $keyword){
			$text = $keyword['text'];
			$relevance = $keyword['relevance'];
			$results .= sprintf(
			"<tr >
			<td> %s </td>
			<td> %s </td>
			</tr>" 
			, $text, $relevance);
			
		}
		$results .= ' </tbody> </table> </div>';
		return $results;
		
	}
	/** Get a list of taxonomy object from the option database.
	*	@return: a list of taxonomy objects with keys 'name' and 'label'
	*
	*/
	public static function getTaxonomyObjects(){
		$options = get_option( ATAXO_OPTION );
		$list = array();
		$i = 0;
		foreach($options['taxonomies'] as $key=>$value){
			$label = $value['labels']['name'];
			$object = array('name' => $key, 'label' => $label);
			$list[$i] = $object;
			$i++;
		}
		
		return $list;
	}
	
	public static function ajax_process_batch() {

		$bstatus   = isset($_POST['state'])? $_POST['state'] : 'INVA';
		$done_url = admin_url( 'tools.php?page='.self::keyword_slug.'&mode=batch-process-results');
		$err_url = admin_url( 'tools.php?page='.self::keyword_slug.'&mode=batch-process-error');
		switch($bstatus){
			case 'CONT':
				$postids = get_all_post_ids();
				$pageids = get_all_page_ids();
				$settings = getApi_content('alchemy');
				$setting = getApi_BatchSetting($settings);
				$taxonomies = $setting['taxonomies'];
				$postcutoff = $setting['postcutoff'];
				$pagecutoff = $setting['pagecutoff'];
				$target = $setting['target'];
				switch($target){
					case 'all':
						break;
					case 'post':
						$pageids = array();
						break;
					case 'page':
						$postids = array();
						break;
					default:
						$postids = array();
						$pageids = array();
						break;
				}
				// Filter only relevant post and page ids to add (only articles that haven't been added to any of the taxonomies);
				$postids = self::filter_untaxed_docs($postids, $taxonomies);
				$pageids = self::filter_untaxed_docs($pageids, $taxonomies);
				//do_action( 'add_debug_info', $taxonomies, 'Taxonomies' );
				$total = count($postids) + count($pageids);
				$postids = array_slice($postids, 0, 1, true);
				$pageids = array_slice($pageids, 0, 1, true);
				$status = self::processBatch($postids, $pageids, $postcutoff, $pagecutoff, $taxonomies);
				$total = $total - $status['npost'] + $status['npage'];
				$sstate = ($total > 0)? 'CONT' : 'DONE';
				echo json_encode( array( 'state' => $sstate, 'total' => $total  ,'url' => $done_url ) );
				break;
			case 'CANC':
				echo json_encode( array( 'state' => 'CANC', 'url' => $done_url ) );
				break;
				
			default:
				echo json_encode( array( 'state' => 'ERR', 'url' => $err_url ) );
				break;
		}
		$_POST = array();
		die();
	}


}