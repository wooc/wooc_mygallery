<?php
// Classes and libraries for module system
//
// webtrees: Web based Family History software
// Copyright (C) 2015 �ukasz Wile�ski.
//
// This program is free software; you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation; either version 2 of the License, or
// (at your option) any later version.
//
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with this program; if not, write to the Free Software
// Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
//
namespace Wooc\WebtreesAddOns\WoocMyGalleryModule;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Controller\PageController;
use Fisharebest\Webtrees\Database;
use Fisharebest\Webtrees\Filter;
use Fisharebest\Webtrees\Functions\FunctionsEdit;
use Fisharebest\Webtrees\Functions\FunctionsPrint;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Media;
use Fisharebest\Webtrees\Menu;
use Fisharebest\Webtrees\Module;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\CkeditorModule;
use Fisharebest\Webtrees\Module\ModuleMenuInterface;
use Fisharebest\Webtrees\Module\ModuleBlockInterface;
use Fisharebest\Webtrees\Module\ModuleConfigInterface;
use Fisharebest\Webtrees\Query\QueryMedia;
use Fisharebest\Webtrees\Theme;
use Fisharebest\Webtrees\Tree;
use PDO;

class WoocMyGalleryModule extends AbstractModule implements ModuleMenuInterface, ModuleBlockInterface, ModuleConfigInterface {

	public function __construct() {
		parent::__construct('wooc_mygallery');
	}

	// Extend class Module
	public function getTitle() {
		return I18N::translate('Wooc Gallery');
	}
	
	public function getMenuTitle() {
		return I18N::translate('Gallery');
	}

	// Extend class Module
	public function getDescription() {
		return I18N::translate('Display image galleries.');
	}

	// Implement Module_Menu
	public function defaultMenuOrder() {
		return 40;
	}

	// Extend class Module
	public function defaultAccessLevel() {
		return Auth::PRIV_NONE;
	}

	// Implement Module_Config
	public function getConfigLink() {
		return 'module.php?mod='.$this->getName().'&amp;mod_action=admin_config';
	}

	// Implement class Module_Block
	public function getBlock($block_id, $template = true, $cfg = null) {
	}

	// Implement class Module_Block
	public function loadAjax() {
		return false;
	}

	// Implement class Module_Block
	public function isUserBlock() {
		return false;
	}

	// Implement class Module_Block
	public function isGedcomBlock() {
		return false;
	}

	// Implement class Module_Block
	public function configureBlock($block_id) {
	}

	// Implement Module_Menu
	public function getMenu() {
		global $controller, $WT_TREE;
		
		$args                = array();
		$args['block_order'] = 0;
		$args['module_name'] = $this->getName();
		
		$block_id = Filter::get('block_id');
		$default_block = Database::prepare(
			"SELECT block_id FROM `##block` WHERE block_order=:block_order AND module_name=:module_name"
		)->execute($args)->fetchOne();

		if (Auth::isSearchEngine()) {
			return null;
		}
		
		if (file_exists(WT_MODULES_DIR . $this->getName() . '/themes/' . Theme::theme()->themeId() . '/')) {
			echo '<link rel="stylesheet" href="' . WT_MODULES_DIR . $this->getName() . '/themes/' . Theme::theme()->themeId() . '/style.css" type="text/css">';
		} else {
			echo '<link rel="stylesheet" href="' . WT_MODULES_DIR . $this->getName() . '/themes/webtrees/style.css" type="text/css">';
		}

		//-- main GALLERIES menu item
		$menu = new Menu($this->getMenuTitle(), 'module.php?mod=' . $this->getName() . '&amp;mod_action=show&amp;album_id=' . $default_block, $this->getName());
		$menu->addClass('menuitem', 'menuitem_hover', '');
		foreach ($this->getMenuAlbumList() as $item) {
			$languages = $this->getBlockSetting($item->block_id, 'languages');
			if ((!$languages || in_array(WT_LOCALE, explode(',', $languages))) && $item->album_access >= Auth::accessLevel($WT_TREE)) {
				$path = 'module.php?mod=' . $this->getName() . '&amp;mod_action=show&amp;album_id=' . $item->block_id;
				$submenu = new Menu(I18N::translate($item->album_title), $path, $this->getName() . '-' . $item->block_id);
				$menu->addSubmenu($submenu);
			}
		}
		if (Auth::isAdmin()) {
			$submenu = new Menu(I18N::translate('Edit albums'), $this->getConfigLink(), $this->getName() . '-edit');
			$menu->addSubmenu($submenu);
		}
		return $menu;
	}

	// Extend Module
	public function modAction($mod_action) {
		switch($mod_action) {
		case 'show':
			$this->show();
			break;
		case 'admin_config':
			$this->config();
			break;
		case 'admin_delete':
			$this->delete();
			$this->config();
			break;
		case 'admin_edit':
			$this->edit();
			break;
		case 'admin_movedown':
			$this->movedown();
			$this->config();
			break;
		case 'admin_moveup':
			$this->moveup();
			$this->config();
			break;
		default:
			http_response_code(404);
		}
	}

	// Action from the configuration page
	private function edit() {
		global $MEDIA_DIRECTORY, $WT_TREE;
		$args = array();
		
		if (Filter::postBool('save') && Filter::checkCsrf()) {
			$block_id = Filter::post('block_id');
			
			if ($block_id) {
				$args['tree_id']     = Filter::post('gedcom_id');
				$args['block_order'] = (int)Filter::post('block_order');
				$args['block_id']    = $block_id;
				Database::prepare(
					"UPDATE `##block` SET gedcom_id=NULLIF(:tree_id, ''), block_order=:block_order WHERE block_id=:block_id"
				)->execute($args);
			} else {
				$args['tree_id']     = Filter::post('gedcom_id');
				$args['module_name'] = $this->getName();
				$args['block_order'] = (int)Filter::post('block_order');
				Database::prepare(
					"INSERT INTO `##block` (gedcom_id, module_name, block_order) VALUES (NULLIF(:tree_id, ''), :module_name, :block_order)"
				)->execute($args);
				$block_id = Database::getInstance()->lastInsertId();
			}
			$this->setBlockSetting($block_id, 'album_title',       Filter::post('album_title')); // allow html
			$this->setBlockSetting($block_id, 'album_description', Filter::post('album_description')); // allow html
			$this->setBlockSetting($block_id, 'album_folder_w',	   Filter::post('album_folder_w'));
			$this->setBlockSetting($block_id, 'album_folder_f',	   Filter::post('album_folder_f'));
			$this->setBlockSetting($block_id, 'album_folder_p',	   Filter::post('album_folder_p'));
			$this->setBlockSetting($block_id, 'album_access',	   Filter::post('album_access'));
			$this->setBlockSetting($block_id, 'plugin',			   Filter::post('plugin'));
			$languages = array();
			foreach (I18N::activeLocales() as $locale) {
				$code = $locale->languageTag();
				$name = $locale->endonym();
				if (Filter::postBool('lang_'.$code)) {
					$languages[] = $code;
				}
			}
			$this->setBlockSetting($block_id, 'languages', implode(',', $languages));
			$this->config();
		} else {
			$block_id = Filter::get('block_id');
			$controller = new PageController();
            $controller->restrictAccess(Auth::isEditor($WT_TREE));
			if ($block_id) {
				$controller->setPageTitle(I18N::translate('Edit album'));
				$item_title       = $this->getBlockSetting($block_id, 'album_title');
				$item_description = $this->getBlockSetting($block_id, 'album_description');
				$item_folder_w    = $this->getBlockSetting($block_id, 'album_folder_w');
				$item_folder_f    = $this->getBlockSetting($block_id, 'album_folder_f');
				$item_folder_p    = $this->getBlockSetting($block_id, 'album_folder_p');
				$item_access      = $this->getBlockSetting($block_id, 'album_access');
				$plugin           = $this->getBlockSetting($block_id, 'plugin');
				$args['block_id'] = $block_id;
				$block_order      = Database::prepare(
					"SELECT block_order FROM `##block` WHERE block_id=:block_id"
				)->execute($args)->fetchOne();
				$gedcom_id        = Database::prepare(
					"SELECT gedcom_id FROM `##block` WHERE block_id=:block_id"
				)->execute($args)->fetchOne();
			} else {
				$controller->setPageTitle(I18N::translate('Add album to gallery'));
				$item_title          = '';
				$item_description    = '';
				$item_folder_w       = $MEDIA_DIRECTORY;
				$item_folder_f       = '';
				$item_folder_p       = '';
				$item_access         = 1;
				$plugin              = 'webtrees';
				$args['module_name'] = $this->getName();
				$block_order         = Database::prepare(
					"SELECT IFNULL(MAX(block_order)+1, 0) FROM `##block` WHERE module_name=:module_name"
				)->execute($args)->fetchOne();
                $gedcom_id           = $WT_TREE->getTreeId();
			}
			$controller
				->pageHeader()
				->addInlineJavaScript('
					function hide_fields(){ 
						if (jQuery("#webtrees-radio").is(":checked")){ 
							jQuery("#fs_album_folder_w").prop("disabled", false);
							jQuery("#fs_album_folder_f").prop("disabled", true);
							jQuery("#fs_album_folder_p").prop("disabled", true);
						}
						else if (jQuery("#flickr-radio").is(":checked")){ 
							jQuery("#fs_album_folder_w").prop("disabled", true);
							jQuery("#fs_album_folder_f").prop("disabled", false);
							jQuery("#fs_album_folder_p").prop("disabled", true);
						}
						else if (jQuery("#picasa-radio").is(":checked")){ 
							jQuery("#fs_album_folder_w").prop("disabled", true);
							jQuery("#fs_album_folder_f").prop("disabled", true);
							jQuery("#fs_album_folder_p").prop("disabled", false);
						}
					};
					jQuery("#galleryForm").on("submit", function() {
						jQuery("#fs_album_folder_w").prop("disabled", false);
						jQuery("#fs_album_folder_f").prop("disabled", false);
						jQuery("#fs_album_folder_p").prop("disabled", false);
					});
				');
			
			if (Module::getModuleByName('ckeditor')) {
				Module\CkeditorModule::enableEditor($controller);
			}
			?>
			
			<ol class="breadcrumb small">
				<li><a href="admin.php"><?php echo I18N::translate('Control panel'); ?></a></li>
				<li><a href="admin_modules.php"><?php echo I18N::translate('Module administration'); ?></a></li>
				<li><a href="module.php?mod=<?php echo $this->getName(); ?>&mod_action=admin_config"><?php echo I18N::translate($this->getTitle()); ?></a></li>
				<li class="active"><?php echo $controller->getPageTitle(); ?></li>
			</ol>

			<form class="form-horizontal" method="POST" action="#" name="gallery" id="galleryForm">
				<?php echo Filter::getCsrf(); ?>
				<input type="hidden" name="save" value="1">
				<input type="hidden" name="block_id" value="<?php echo $block_id; ?>">
				<h3><?php echo I18N::translate('General'); ?></h3>
				
				<div class="form-group">
					<label class="control-label col-sm-3" for="title">
						<?php echo I18N::translate('Title'); ?>
					</label>
					<div class="col-sm-9">
						<input
							class="form-control"
							id="title"
							size="90"
							name="album_title"
							required
							type="text"
							value="<?php echo Filter::escapeHtml($item_title); ?>"
							>
					</div>
				</div>
				<div class="form-group">
					<label class="control-label col-sm-3" for="description">
						<?php echo I18N::translate('Description'); ?>
					</label>
					<div class="col-sm-9">
						<textarea
							class="form-control html-edit"
							id="description"
							rows="10"
							cols="90"
							name="album_description"
							required
							type="text"><?php echo Filter::escapeHtml($item_description); ?></textarea>
					</div>
				</div>
				
				<h3><?php echo I18N::translate('Source'); ?></h3>
				<span class="help-block small text-muted">
					<?php echo I18N::translate('Here you can either select the webtrees media folder to display in this album page, or you can set a link to a Flickr or Picasa location for your group of images.<br><em>[Such external sources must be public or they will not be viewable in webtrees.]</em><br><br>The module will add these references to the correct URLs to link to your Flickr or Picasa sites.'); ?>
				</span>
				<div class="form-group">
					<label class="control-label col-sm-3" for="plugin">
						<?php echo I18N::translate('Gallery Source'); ?>
					</label>
					<div class="row col-sm-9">
						<div class="col-sm-4">
							<label class="radio-inline">
								<input id="webtrees-radio" type="radio" name="plugin" value="webtrees" <?php if ($plugin=='webtrees') {echo 'checked'; } ?> onclick="hide_fields();"><?php echo I18N::translate('webtrees'); ?>
							</label>
						</div>
						<div class="col-sm-4">
							<label class="radio-inline ">
								<input id="flickr-radio" type="radio" name="plugin" value="flickr" <?php if ($plugin=='flickr') {echo 'checked'; } ?> onclick="hide_fields();"><?php echo I18N::translate('Flickr'); ?>
							</label>
						</div>
						<div class="col-sm-4">
							<label class="radio-inline ">
								<input id="picasa-radio" type="radio" name="plugin" value="picasa" <?php if ($plugin=='picasa') {echo 'checked'; } ?> onclick="hide_fields();"><?php echo I18N::translate('Picasa'); ?>
							</label>
						</div>
					</div>
				</div>
				<fieldset id="fs_album_folder_w" <?php if ($plugin!=='webtrees') { echo 'disabled="disabled"'; } ?>>
					<div class="form-group">
						<label class="control-label col-sm-3" for="album_folder_w">
							<?php echo I18N::translate('Folder name on server'); ?>
						</label>
						<div class="col-sm-9" >
							<div class="input-group">
								<span class="input-group-addon">
									<?php echo WT_DATA_DIR, $MEDIA_DIRECTORY; ?>
								</span>
								<?php echo FunctionsEdit::selectEditControl('album_folder_w', QueryMedia::folderList(), null, htmlspecialchars($item_folder_w), 'class="form-control"'); ?>
							</div>
						</div>
					</div>
				</fieldset>
				<fieldset id="fs_album_folder_f" <?php if ($plugin!=='flickr') { echo 'disabled="disabled"'; } ?>>
					<div class="form-group">
						<label class="control-label col-sm-3" for="album_folder_f">
							<?php echo I18N::translate('Flickr set number'); ?>
						</label>
						<div class="col-sm-9">
							<input
							class="form-control"
							id="album_folder_f"
							size="90"
							name="album_folder_f"
							required
							type="number"
							value="<?php echo Filter::escapeHtml($item_folder_f); ?>"
							>
						</div>
						<span class="help-block col-sm-9 col-sm-offset-3 small text-muted">
							<?php echo I18N::translate('For Flickr (www.flickr.com), enter the Set number of your images, usually a long number like <strong>72157633272831222</strong>. Nothing else is required in this field.'); ?>
						</span>
					</div>
				</fieldset>
				<fieldset id="fs_album_folder_p" <?php if ($plugin!=='picasa') { echo 'disabled="disabled"'; } ?>>
					<div class="form-group">
						<label class="control-label col-sm-3" for="album_folder_p">
							<?php echo I18N::translate('Picasa user/album'); ?>
						</label>
						<div class="col-sm-9">
							<input
							class="form-control"
							id="album_folder_p"
							size="90"
							name="album_folder_p"
							required
							type="text"
							value="<?php echo Filter::escapeHtml($item_folder_p); ?>"
							>
						</div>
						<span class="help-block col-sm-9 col-sm-offset-3 small text-muted">
							<?php echo I18N::translate('For Picassa (picasaweb.google.com) enter your user name and user album, in the format <strong>username/album</strong> like <strong>wooc/photos</strong>'); ?>
						</span>
					</div>
				</fieldset>
				
				<h3><?php echo I18N::translate('Languages'); ?></h3>
				
				<div class="form-group">
					<label class="control-label col-sm-3" for="lang_*">
						<?php echo I18N::translate('Show this album for which languages?'); ?>
					</label>
					<div class="col-sm-9">
						<?php 
							$accepted_languages=explode(',', $this->getBlockSetting($block_id, 'languages'));
							foreach (I18N::activeLocales() as $locale) {
						?>
								<div class="checkbox">
									<label title="<?php echo $locale->languageTag(); ?>">
										<input type="checkbox" name="lang_<?php echo $locale->languageTag(); ?>" <?php echo in_array($locale->languageTag(), $accepted_languages) ? 'checked' : ''; ?> ><?php echo $locale->endonym(); ?>
									</label>
								</div>
						<?php 
							}
						?>
					</div>
				</div>
				
				<h3><?php echo I18N::translate('Visibility and Access'); ?></h3>
				
				<div class="form-group">
					<label class="control-label col-sm-3" for="block_order">
						<?php echo I18N::translate('Album position'); ?>
					</label>
					<div class="col-sm-9">
						<input
							class="form-control"
							id="position"
							name="block_order"
							size="3"
							required
							type="number"
							value="<?php echo Filter::escapeHtml($block_order); ?>"
						>
					</div>
					<span class="help-block col-sm-9 col-sm-offset-3 small text-muted">
						<?php 
							echo I18N::translate('This field controls the order in which the gallery albums are displayed.'),
							'<br><br>',
							I18N::translate('You do not have to enter the numbers sequentially. If you leave holes in the numbering scheme, you can insert other albums later. For example, if you use the numbers 1, 6, 11, 16, you can later insert albums with the missing sequence numbers. Negative numbers and zero are allowed, and can be used to insert albums in front of the first one.'),
							'<br><br>',
							I18N::translate('When more than one gallery album has the same position number, only one of these albums will be visible.'); 
						?>
					</span>
				</div>
				<div class="form-group">
					<label class="control-label col-sm-3" for="block_order">
						<?php echo I18N::translate('Album visibility'); ?>
					</label>
					<div class="col-sm-9">
						<?php echo FunctionsEdit::selectEditControl('gedcom_id', Tree::getIdList(), I18N::translate('All'), $gedcom_id, 'class="form-control"'); ?>
					</div>
					<span class="help-block col-sm-9 col-sm-offset-3 small text-muted">
						<?php 
							echo I18N::translate('You can determine whether this album will be visible regardless of family tree, or whether it will be visible only to the selected family tree.'); 
						?>
					</span>
				</div>
				<div class="form-group">
					<label class="control-label col-sm-3" for="album_access">
						<?php echo I18N::translate('Access level'); ?>
					</label>
					<div class="col-sm-9">
						<?php echo FunctionsEdit::editFieldAccessLevel('album_access', $item_access, 'class="form-control"'); ?>
					</div>
				</div>
				
				<div class="row col-sm-9 col-sm-offset-3">
					<button class="btn btn-primary" type="submit">
						<i class="fa fa-check"></i>
						<?php echo I18N::translate('save'); ?>
					</button>
					<button class="btn" type="button" onclick="window.location='<?php echo $this->getConfigLink(); ?>';">
						<i class="fa fa-close"></i>
						<?php echo I18N::translate('cancel'); ?>
					</button>
				</div>
			</form>
<?php
		}
	}

	private function delete() {
		global $WT_TREE;
        
        if (Auth::isManager($WT_TREE)) {
			$args             = array();
			$args['block_id'] = Filter::get('block_id');

			Database::prepare(
				"DELETE FROM `##block_setting` WHERE block_id=:block_id"
			)->execute($args);

			Database::prepare(
				"DELETE FROM `##block` WHERE block_id=:block_id"
			)->execute($args);
		} else {
			header('Location: ' . WT_BASE_URL);
			exit;
		}
	}

	private function moveup() {
		global $WT_TREE;
        
        if (Auth::isManager($WT_TREE)) {
			$block_id         = Filter::get('block_id');
			$args             = array();
			$args['block_id'] = $block_id;

			$block_order = Database::prepare(
				"SELECT block_order FROM `##block` WHERE block_id=:block_id"
			)->execute($args)->fetchOne();

			$args                = array();
			$args['module_name'] = $this->getName();
			$args['block_order'] = $block_order;
			
			$swap_block = Database::prepare(
				"SELECT block_order, block_id".
				" FROM `##block`".
				" WHERE block_order = (".
				"  SELECT MAX(block_order) FROM `##block` WHERE block_order < :block_order AND module_name = :module_name".
				" ) AND module_name = :module_name".
				" LIMIT 1"
			)->execute($args)->fetchOneRow();
			if ($swap_block) {
				$args                = array();
				$args['block_id']    = $block_id;
				$args['block_order'] = $swap_block->block_order;
				Database::prepare(
					"UPDATE `##block` SET block_order = :block_order WHERE block_id = :block_id"
				)->execute($args);
				
				$args                = array();
				$args['block_order'] = $block_order;
				$args['block_id']    = $swap_block->block_id;
				Database::prepare(
					"UPDATE `##block` SET block_order = :block_order WHERE block_id = :block_id"
				)->execute($args);
			}
		} else {
			header('Location: ' . WT_BASE_URL);
			exit;
		}
	}

	private function movedown() {
		global $WT_TREE;
        
        if (Auth::isManager($WT_TREE)) {
			$block_id         = Filter::get('block_id');
			$args             = array();
			$args['block_id'] = $block_id;

			$block_order = Database::prepare(
				"SELECT block_order FROM `##block` WHERE block_id=:block_id"
			)->execute($args)->fetchOne();

			$args                = array();
			$args['module_name'] = $this->getName();
			$args['block_order'] = $block_order;
			
			$swap_block = Database::prepare(
				"SELECT block_order, block_id".
				" FROM `##block`".
				" WHERE block_order = (".
				"  SELECT MIN(block_order) FROM `##block` WHERE block_order > :block_order AND module_name = :module_name".
				" ) AND module_name = :module_name".
				" LIMIT 1"
			)->execute($args)->fetchOneRow();
			if ($swap_block) {
				$args                = array();
				$args['block_id']    = $block_id;
				$args['block_order'] = $swap_block->block_order;
				Database::prepare(
					"UPDATE `##block` SET block_order = :block_order WHERE block_id = :block_id"
				)->execute($args);
				
				$args                = array();
				$args['block_order'] = $block_order;
				$args['block_id']    = $swap_block->block_id;
				Database::prepare(
					"UPDATE `##block` SET block_order = :block_order WHERE block_id = :block_id"
				)->execute($args);
			}
		} else {
			header('Location: ' . WT_BASE_URL);
			exit;
		}
	}

	private function show() {
		global $MEDIA_DIRECTORY, $controller, $WT_TREE;
		$gallery_header_description = '';
		$item_id = Filter::get('album_id');
		$controller = new PageController();
		$controller
			->setPageTitle(I18N::translate('Picture galleries'))
			->pageHeader()
			->addExternalJavaScript(WT_STATIC_URL . WT_MODULES_DIR . $this->getName() . '/galleria/galleria-1.4.2.min.js')
			->addExternalJavaScript(WT_STATIC_URL . WT_MODULES_DIR . $this->getName() . '/galleria/plugins/flickr/galleria.flickr.min.js')
			->addExternalJavaScript(WT_STATIC_URL . WT_MODULES_DIR . $this->getName() . '/galleria/plugins/picasa/galleria.picasa.min.js')
			->addInlineJavaScript($this->getJavaScript($item_id));
		?>
		<div id="gallery-page">
			<div id="gallery-container">
				<h2><?php echo $controller->getPageTitle(); ?></h2>
				<?php echo $gallery_header_description; ?>
				<div style="clear:both;"></div>
				<div id="gallery_tabs" class="ui-tabs ui-widget ui-widget-content ui-corner-all">
					<ul class="ui-tabs-nav ui-helper-reset ui-helper-clearfix ui-widget-header ui-corner-all">
					<?php 
						$item_list = $this->getAlbumList();
						foreach ($item_list as $item) {
							$languages = $this->getBlockSetting($item->block_id, 'languages');
							if ((!$languages || in_array(WT_LOCALE, explode(',', $languages))) && $item->album_access >= Auth::accessLevel($WT_TREE)) { ?>
								<li class="ui-state-default ui-corner-top <?php echo ($item_id == $item->block_id ? ' ui-tabs-selected ui-state-active' : ''); ?>">
									<a href="module.php?mod=<?php echo $this->getName(); ?>&amp;mod_action=show&amp;album_id=<?php echo $item->block_id; ?>">
										<span title="<?php echo I18N::translate($item->album_title); ?>"><?php echo I18N::translate($item->album_title); ?></span>
									</a>
								</li>
							<?php 
							}
						} 
					?>
					</ul>
					<div id="outer_gallery_container" style="padding: 1em;">
					<?php 
						foreach ($item_list as $item) {
							$languages = $this->getBlockSetting($item->block_id, 'languages');
							if ((!$languages || in_array(WT_LOCALE, explode(',', $languages))) && $item_id == $item->block_id && $item->album_access >= Auth::accessLevel($WT_TREE)) {
								$item_gallery='<h4>' . I18N::translate($item->album_description) . '</h4>' . $this->mediaDisplay($item->album_folder_w, $item_id);
							}
						}
						if (!isset($item_gallery)) {
							echo '<h4>' . I18N::translate('Image collections related to our family') . '</h4>' . $this->mediaDisplay('//', $item_id);
						} else {
							echo $item_gallery;
						}
					?>
					</div>
				</div>
			</div>
			<?php
	}

	private function config() {
		global $WT_TREE;
		
		$controller = new PageController();
		$controller
			->restrictAccess(Auth::isManager($WT_TREE))
			->setPageTitle($this->getTitle())
			->pageHeader();

		$args                = array();
		$args['module_name'] = $this->getName();
        $args['tree_id']     = $WT_TREE->getTreeId();
		$albums              = Database::prepare(
			"SELECT block_id, block_order, gedcom_id, bs1.setting_value AS album_title, bs2.setting_value AS album_description" .
			" FROM `##block` b" .
			" JOIN `##block_setting` bs1 USING (block_id)" .
			" JOIN `##block_setting` bs2 USING (block_id)" .
			" WHERE module_name = :module_name" .
			" AND bs1.setting_name = 'album_title'" .
			" AND bs2.setting_name = 'album_description'" .
			" AND IFNULL(gedcom_id, :tree_id) = :tree_id" .
			" ORDER BY block_order"
		)->execute($args)->fetchAll();

		unset($args['tree_id']);
		$min_block_order = Database::prepare(
			"SELECT MIN(block_order) FROM `##block` WHERE module_name = :module_name"
		)->execute($args)->fetchOne();

		$max_block_order = Database::prepare(
			"SELECT MAX(block_order) FROM `##block` WHERE module_name = :module_name"
		)->execute($args)->fetchOne();
		?>
		<style>
			.text-left-not-xs, .text-left-not-sm, .text-left-not-md, .text-left-not-lg {
				text-align: left;
			}
			.text-center-not-xs, .text-center-not-sm, .text-center-not-md, .text-center-not-lg {
				text-align: center;
			}
			.text-right-not-xs, .text-right-not-sm, .text-right-not-md, .text-right-not-lg {
				text-align: right;
			}
			.text-justify-not-xs, .text-justify-not-sm, .text-justify-not-md, .text-justify-not-lg {
				text-align: justify;
			}

			@media (max-width: 767px) {
				.text-left-not-xs, .text-center-not-xs, .text-right-not-xs, .text-justify-not-xs {
					text-align: inherit;
				}
				.text-left-xs {
					text-align: left;
				}
				.text-center-xs {
					text-align: center;
				}
				.text-right-xs {
					text-align: right;
				}
				.text-justify-xs {
					text-align: justify;
				}
			}
			@media (min-width: 768px) and (max-width: 991px) {
				.text-left-not-sm, .text-center-not-sm, .text-right-not-sm, .text-justify-not-sm {
					text-align: inherit;
				}
				.text-left-sm {
					text-align: left;
				}
				.text-center-sm {
					text-align: center;
				}
				.text-right-sm {
					text-align: right;
				}
				.text-justify-sm {
					text-align: justify;
				}
			}
			@media (min-width: 992px) and (max-width: 1199px) {
				.text-left-not-md, .text-center-not-md, .text-right-not-md, .text-justify-not-md {
					text-align: inherit;
				}
				.text-left-md {
					text-align: left;
				}
				.text-center-md {
					text-align: center;
				}
				.text-right-md {
					text-align: right;
				}
				.text-justify-md {
					text-align: justify;
				}
			}
			@media (min-width: 1200px) {
				.text-left-not-lg, .text-center-not-lg, .text-right-not-lg, .text-justify-not-lg {
					text-align: inherit;
				}
				.text-left-lg {
					text-align: left;
				}
				.text-center-lg {
					text-align: center;
				}
				.text-right-lg {
					text-align: right;
				}
				.text-justify-lg {
					text-align: justify;
				}
			}
		</style>
		
		<ol class="breadcrumb small">
			<li><a href="admin.php"><?php echo I18N::translate('Control panel'); ?></a></li>
			<li><a href="admin_modules.php"><?php echo I18N::translate('Module administration'); ?></a></li>
			<li class="active"><?php echo $controller->getPageTitle(); ?></li>
		</ol>
		
		<div class="row">
			<div class="col-sm-4 col-xs-12">
				<form class="form">
					<label for="ged" class="sr-only">
						<?php echo I18N::translate('Family tree'); ?>
					</label>
					<input type="hidden" name="mod" value="<?php echo  $this->getName(); ?>">
					<input type="hidden" name="mod_action" value="admin_config">
					<div class="col-sm-9 col-xs-9" style="padding:0;">
						<?php echo FunctionsEdit::selectEditControl('ged', Tree::getNameList(), null, $WT_TREE->getName(), 'class="form-control"'); ?>
					</div>
					<div class="col-sm-3" style="padding:3px;">
						<input type="submit" class="btn btn-primary" value="<?php echo I18N::translate('show'); ?>">
					</div>
				</form>
			</div>
			<span class="visible-xs hidden-sm hidden-md hidden-lg" style="display:block;"></br></br></span>
			<div class="col-sm-4 text-center text-left-xs col-xs-12">
				<p>
					<a href="module.php?mod=<?php echo $this->getName(); ?>&amp;mod_action=admin_edit" class="btn btn-primary">
						<i class="fa fa-plus"></i>
						<?php echo I18N::translate('Add album to gallery'); ?>
					</a>
				</p>
			</div>
			<div class="col-sm-4 text-right text-left-xs col-xs-12">		
				<?php // TODO: Move to internal item/page
				if (file_exists(WT_MODULES_DIR . $this->getName() . '/readme.html')) { ?>
					<a href="<?php echo WT_MODULES_DIR . $this->getName(); ?>/readme.html" class="btn btn-info">
						<i class="fa fa-newspaper-o"></i>
						<?php echo I18N::translate('ReadMe'); ?>
					</a>
				<?php } ?>
			</div>
		</div>
		
		<table class="table table-bordered table-condensed">
			<thead>
				<tr>
					<th class="col-sm-2"><?php echo I18N::translate('Position'); ?></th>
					<th class="col-sm-3"><?php echo I18N::translate('Album title'); ?></th>
					<th class="col-sm-1" colspan=4><?php echo I18N::translate('Controls'); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($albums as $album): ?>
				<tr>
					<td>
						<?php echo $album->block_order, ', ';
						if ($album->gedcom_id == null) {
							echo I18N::translate('All');
						} else {
							echo Tree::findById($album->gedcom_id)->getTitleHtml();
						} ?>
					</td>
					<td>
						<?php echo Filter::escapeHtml(I18N::translate($album->album_title)); ?>
					</td>
					<td class="text-center">
						<a href="module.php?mod=<?php echo $this->getName(); ?>&amp;mod_action=admin_edit&amp;block_id=<?php echo $album->block_id; ?>">
							<div class="icon-edit">&nbsp;</div>
						</a>
					</td>
					<td class="text-center">
						<a href="module.php?mod=<?php echo $this->getName(); ?>&amp;mod_action=admin_moveup&amp;block_id=<?php echo $album->block_id; ?>">
							<?php
								if ($album->block_order == $min_block_order) {
									echo '&nbsp;';
								} else {
									echo '<div class="icon-uarrow">&nbsp;</div>';
								} 
							?>
						</a>
					</td>
					<td class="text-center">
						<a href="module.php?mod=<?php echo $this->getName(); ?>&amp;mod_action=admin_movedown&amp;block_id=<?php echo $album->block_id; ?>">
							<?php
								if ($album->block_order == $max_block_order) {
									echo '&nbsp;';
								} else {
									echo '<div class="icon-darrow">&nbsp;</div>';
								} 
							?>
						</a>
					</td>
					<td class="text-center">
						<a href="module.php?mod=<?php echo $this->getName(); ?>&amp;mod_action=admin_delete&amp;block_id=<?php echo $album->block_id; ?>"
							onclick="return confirm('<?php echo I18N::translate('Are you sure you want to delete this album?'); ?>');">
							<div class="icon-delete">&nbsp;</div>
						</a>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function getJavaScript($item_id) {
		$theme = "classic";// alternatives: "azur"
		$plugin = $this->getBlockSetting($item_id, 'plugin');
		$js = 'Galleria.loadTheme("' . WT_STATIC_URL . WT_MODULES_DIR . $this->getName() . '/galleria/themes/' . $theme . '/galleria.' . $theme.'.js");';
			switch ($plugin) {
			case 'flickr':
			$flickr_set = $this->getBlockSetting($item_id, 'album_folder_f');
			$js .= '
				Galleria.run("#galleria", {
					flickr: "set:' . $flickr_set . '",
					flickrOptions: {
						sort: "date-posted-asc",
						description: true,
						imageSize: "big"
					},
					_showCaption: false,
					imageCrop: true,
					decription: true
				});
			';
			break;
			case 'picasa':
			$picasa_set = $this->getBlockSetting($item_id, 'album_folder_p');
			$js .= '
				Galleria.run("#galleria", {
					picasa: "useralbum:' . $picasa_set . '",
					picasaOptions: {
						sort: "date-posted-asc"
					},
					_showCaption: false,
					imageCrop: true
				});
			';
			break;
			default:		
			$js .= '
				Galleria.ready(function(options) {
					this.bind("image", function(e) {
						data = e.galleriaData;
						$("#links_bar").html(data.layer);
					});
				});
				Galleria.run("#galleria", {
					_showCaption: false,
					_locale: {
						show_captions:		"' . I18N::translate('Show descriptions') . '",
						hide_captions:		"' . I18N::translate('Hide descriptions') . '",
						play:				"' . I18N::translate('Play slideshow') . '",
						pause:				"' . I18N::translate('Pause slideshow') . '",
						enter_fullscreen:	"' . I18N::translate('Enter fullscreen') . '",
						exit_fullscreen:	"' . I18N::translate('Exit fullscreen') . '",
						next:				"' . I18N::translate('Next image') . '",
						prev:				"' . I18N::translate('Previous image') . '",
						showing_image:		"" // counter not compatible with I18N of webtrees
					}
				});
			';			
			break;
		}
		return $js;
	}

	// Return the list of albums
	private function getAlbumList() {
		global $WT_TREE;
		
		$args                = array();
		$args['module_name'] = $this->getName();
        $args['tree_id']     = $WT_TREE->getTreeId();
		return Database::prepare(
			"SELECT block_id, 
				bs1.setting_value AS album_title, 
				bs2.setting_value AS album_access, 
				bs3.setting_value AS album_description, 
				bs4.setting_value AS album_folder_w, 
				bs5.setting_value AS album_folder_f, 
				bs6.setting_value AS album_folder_p" . 
			" FROM `##block` b" . 
			" JOIN `##block_setting` bs1 USING (block_id)" .
			" JOIN `##block_setting` bs2 USING (block_id)" .
			" JOIN `##block_setting` bs3 USING (block_id)" .
			" JOIN `##block_setting` bs4 USING (block_id)" .
			" JOIN `##block_setting` bs5 USING (block_id)" .
			" JOIN `##block_setting` bs6 USING (block_id)" .
			" WHERE module_name = :module_name" .
			" AND bs1.setting_name = 'album_title'" .
			" AND bs2.setting_name = 'album_access'" .
			" AND bs3.setting_name = 'album_description'" .
			" AND bs4.setting_name = 'album_folder_w'" .
			" AND bs5.setting_name = 'album_folder_f'" .
			" AND bs6.setting_name = 'album_folder_p'" .
			" AND (gedcom_id IS NULL OR gedcom_id = :tree_id)" .
			" ORDER BY block_order"
		)->execute($args)->fetchAll();
	}
	
	// Return the list of albums for menu
	private function getMenuAlbumList() {
		global $WT_TREE;
		
		$args                = array();
		$args['module_name'] = $this->getName();
        $args['tree_id']     = $WT_TREE->getTreeId();
		return Database::prepare(
			"SELECT block_id, bs1.setting_value AS album_title, bs2.setting_value AS album_access".
			" FROM `##block` b".
			" JOIN `##block_setting` bs1 USING (block_id)".
			" JOIN `##block_setting` bs2 USING (block_id)".
			" WHERE module_name = :module_name".
			" AND bs1.setting_name = 'album_title'".
			" AND bs2.setting_name = 'album_access'".
			" AND (gedcom_id IS NULL OR gedcom_id = :tree_id)".
			" ORDER BY block_order"
		)->execute($args)->fetchAll();
	}

	// Print the Notes for each media item
	static function FormatGalleryNotes($haystack) {
		$needle   = '1 NOTE';
		$before   = substr($haystack, 0, strpos($haystack, $needle));
		$after    = substr(strstr($haystack, $needle), strlen($needle));
		$final    = $before.$needle.$after;
		$notes    = FunctionsPrint::printFactNotes($final, 1, true, true);
		if ($notes != '' && $notes != '<br>') {
			$html = htmlspecialchars($notes);
			return $html;
		}
		return false;
	}

	// Print the gallery display
	private function mediaDisplay($sub_folder, $item_id) {
		global $MEDIA_DIRECTORY, $WT_TREE;
		$plugin = $this->getBlockSetting($item_id, 'plugin');
		$images = ''; 
		// Get the related media items
		$sub_folder = str_replace($MEDIA_DIRECTORY, "", $sub_folder);
		$sql = "SELECT * FROM ##media WHERE m_filename LIKE '%" . $sub_folder . "%' ORDER BY m_filename";
		$rows = Database::prepare($sql)->execute()->fetchAll(PDO::FETCH_ASSOC);
		if ($plugin == 'webtrees') {
			foreach ($rows as $rowm) {
				// Get info on how to handle this media file
				$media = Media::getInstance($rowm['m_id'], $WT_TREE);
				if ($media->canShow()) {
					$links = array_merge(
						$media->linkedIndividuals('OBJE'),
						$media->linkedFamilies('OBJE'),
						$media->linkedSources('OBJE')
					);
					$rawTitle = $rowm['m_titl'];
					if (empty($rawTitle)) $rawTitle = get_gedcom_value('TITL', 2, $rowm['m_gedcom']);
					if (empty($rawTitle)) $rawTitle = basename($rowm['m_filename']);
					$mediaTitle = htmlspecialchars(strip_tags($rawTitle));
					$rawUrl = $media->getHtmlUrlDirect();
					$thumbUrl = $media->getHtmlUrlDirect('thumb');
					$media_notes = $this->FormatGalleryNotes($rowm['m_gedcom']);
					$mime_type = $media->mimeType();
					$gallery_links = '';
					if (Auth::isEditor($WT_TREE)) {
						$gallery_links .= '<div class="edit_links">';
							$gallery_links .= '<div class="image_option"><a href="' . $media->getHtmlUrl() . '"><img src="' . WT_MODULES_DIR . $this->getName() . '/themes/' . Theme::theme()->themeId() . '/edit.png" title="'.I18N::translate('Edit').'"></a></div>';
							if (Auth::isManager($WT_TREE)) {
								if (Module::getModuleByName('GEDFact_assistant')) {
									$gallery_links .= '<div class="image_option"><a onclick="return ilinkitem(\'' . $rowm['m_id'] . '\', \'manage\')" href="#"><img src="' . WT_MODULES_DIR . $this->getName() . '/themes/' . Theme::theme()->themeId() . '/link.png" title="' . I18N::translate('Manage links') . '"></a></div>';
								}
							}
						$gallery_links .= '</div><hr>';// close .edit_links
					}						
					if ($links) {
						$gallery_links .= '<h4>'.I18N::translate('Linked to:') . '</h4>';
						$gallery_links .= '<div id="image_links">';
							foreach ($links as $record) {
									$gallery_links .= '<a href="' . $record->getHtmlUrl() . '">' . $record->getFullname() . '</a><br>';
							}
						$gallery_links .= '</div>';
					}
					$media_links = htmlspecialchars($gallery_links);
					if ($mime_type == 'application/pdf'){ 
						$images .= '<a href="' . $rawUrl . '"><img class="iframe" src="' . $thumbUrl . '" data-title="' . $mediaTitle . '" data-layer="' . $media_links . '" data-description="' . $media_notes . '"></a>';
					} else {
						$images .= '<a href="' . $rawUrl . '"><img src="' . $thumbUrl . '" data-title="' . $mediaTitle . '" data-layer="' . $media_links . '" data-description="' . $media_notes . '"></a>';
					}
				}
			}
			if (Auth::isMember($WT_TREE) || (isset($media_links) && $media_links != '')) {
				$html =
					'<div id="links_bar"></div>'.
					'<div id="galleria" style="width:80%;">';
			} else {
				$html =
					'<div id="galleria" style="width:100%;">';
			}
		} else {
			$html = '<div id="galleria" style="width:100%;">';
			$images .= '&nbsp;';
		}
		if ($images) {
			$html .= $images.
				'</div>' . // close #galleria
				'<a id="copy" href="http://galleria.io/">Display by Galleria</a>' . // gallery.io attribution
				'</div>' . // close #page
				'<div style="clear: both;"></div>';
		} else {
			$html .= I18N::translate('Album is empty. Please choose other album.') .
				'</div>' . // close #galleria
				'</div>' . // close #page
				'<div style="clear: both;"></div>';
		}
		return $html;
	}
}

return new WoocMyGalleryModule;