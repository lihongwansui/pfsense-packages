<?php
/*
 * suricata_interfaces.php
 *
 * Copyright (C) 2014 Bill Meeks
 * All rights reserved.
 * 
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 * 
 * 1. Redistributions of source code must retain the above copyright notice,
 * this list of conditions and the following disclaimer.
 * 
 * 2. Redistributions in binary form must reproduce the above copyright
 * notice, this list of conditions and the following disclaimer in the
 * documentation and/or other materials provided with the distribution.
 * 
 * THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 * INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 * AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 * OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */

$nocsrf = true;
require_once("guiconfig.inc");
require_once("/usr/local/pkg/suricata/suricata.inc");

global $g, $rebuild_rules;

$suricatadir = SURICATADIR;
$suricatalogdir = SURICATALOGDIR;
$rcdir = RCFILEPREFIX;

$id = $_GET['id'];
if (isset($_POST['id']))
	$id = $_POST['id'];

if (!is_array($config['installedpackages']['suricata']['rule']))
	$config['installedpackages']['suricata']['rule'] = array();
$a_nat = &$config['installedpackages']['suricata']['rule'];
$id_gen = count($config['installedpackages']['suricata']['rule']);

if (isset($_POST['del_x'])) {
	/* delete selected rules */
	if (is_array($_POST['rule'])) {
		conf_mount_rw();
		foreach ($_POST['rule'] as $rulei) {
			/* convert fake interfaces to real */
			$if_real = get_real_interface($a_nat[$rulei]['interface']);
			$suricata_uuid = $a_nat[$rulei]['uuid'];
			suricata_stop($a_nat[$rulei], $if_real);
			exec("/bin/rm -r {$suricatalogdir}suricata_{$if_real}{$suricata_uuid}");
			exec("/bin/rm -r {$suricatadir}suricata_{$suricata_uuid}_{$if_real}");

			// If interface had auto-generated Suppress List, then
			// delete that along with the interface
			$autolist = "{$a_nat[$rulei]['interface']}" . "suppress";
			if (is_array($config['installedpackages']['suricata']['suppress']) && 
			    is_array($config['installedpackages']['suricata']['suppress']['item'])) {
				$a_suppress = &$config['installedpackages']['suricata']['suppress']['item'];
				foreach ($a_suppress as $k => $i) {
					if ($i['name'] == $autolist) {
						unset($config['installedpackages']['suricata']['suppress']['item'][$k]);
						break;
					}
				}
			}

			// Finally delete the interface's config entry entirely
			unset($a_nat[$rulei]);
		}
		conf_mount_ro();
	  
		/* If all the Suricata interfaces are removed, then unset the config array. */
		if (empty($a_nat))
			unset($a_nat);

		write_config();
		sleep(2);
	  
		/* if there are no ifaces remaining do not create suricata.sh */
		if (!empty($config['installedpackages']['suricata']['rule']))
			suricata_create_rc();
		else {
			conf_mount_rw();
			@unlink("{$rcdir}/suricata.sh");
			conf_mount_ro();
		}
	  
		sync_suricata_package_config();
	  
		header( 'Expires: Sat, 26 Jul 1997 05:00:00 GMT' );
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s' ) . ' GMT' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate' );
		header( 'Cache-Control: post-check=0, pre-check=0', false );
		header( 'Pragma: no-cache' );
		header("Location: /suricata/suricata_interfaces.php");
		exit;
	}

}

/* start/stop Barnyard2 */
if ($_GET['act'] == 'bartoggle' && is_numeric($id)) {
	$suricatacfg = $config['installedpackages']['suricata']['rule'][$id];
	$if_real = get_real_interface($suricatacfg['interface']);
	$if_friendly = convert_friendly_interface_to_friendly_descr($suricatacfg['interface']);

	if (suricata_is_running($suricatacfg['uuid'], $if_real, 'barnyard2') == 'no') {
		log_error("Toggle (barnyard starting) for {$if_friendly}({$suricatacfg['descr']})...");
		sync_suricata_package_config();
		suricata_barnyard_start($suricatacfg, $if_real);
	} else {
		log_error("Toggle (barnyard stopping) for {$if_friendly}({$suricatacfg['descr']})...");
		suricata_barnyard_stop($suricatacfg, $if_real);
	}

	sleep(3); // So the GUI reports correctly
	header("Location: /suricata/suricata_interfaces.php");
	exit;
}

/* start/stop Suricata */
if ($_GET['act'] == 'toggle' && is_numeric($id)) {
	$suricatacfg = $config['installedpackages']['suricata']['rule'][$id];
	$if_real = get_real_interface($suricatacfg['interface']);
	$if_friendly = convert_friendly_interface_to_friendly_descr($suricatacfg['interface']);

	if (suricata_is_running($suricatacfg['uuid'], $if_real) == 'yes') {
		log_error("Toggle (suricata stopping) for {$if_friendly}({$suricatacfg['descr']})...");
		suricata_stop($suricatacfg, $if_real);
	} else {
		log_error("Toggle (suricata starting) for {$if_friendly}({$suricatacfg['descr']})...");
		// set flag to rebuild interface rules before starting Snort
		$rebuild_rules = true;
		sync_suricata_package_config();
		$rebuild_rules = false;
		suricata_start($suricatacfg, $if_real);
	}
	sleep(3); // So the GUI reports correctly
	header( 'Expires: Sat, 26 Jul 1997 05:00:00 GMT' );
	header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s' ) . ' GMT' );
	header( 'Cache-Control: no-store, no-cache, must-revalidate' );
	header( 'Cache-Control: post-check=0, pre-check=0', false );
	header( 'Pragma: no-cache' );
	header("Location: /suricata/suricata_interfaces.php");
	exit;
}

$pgtitle = "Services: Suricata Intrusion Detection System";
include_once("head.inc");

?>
<body link="#000000" vlink="#000000" alink="#000000">

<?php
include_once("fbegin.inc");
if ($pfsense_stable == 'yes')
	echo '<p class="pgtitle">' . $pgtitle . '</p>';
?>

<form action="suricata_interfaces.php" method="post" enctype="multipart/form-data" name="iform" id="iform">
<?php
	/* Display Alert message */
	if ($input_errors)
		print_input_errors($input_errors); // TODO: add checks

	if ($savemsg)
		print_info_box($savemsg);
?>

<table width="100%" border="0" cellpadding="0" cellspacing="0">
<tr>
	<td>
	<?php
		$tab_array = array();
		$tab_array[] = array(gettext("Suricata Interfaces"), true, "/suricata/suricata_interfaces.php");
		$tab_array[] = array(gettext("Global Settings"), false, "/suricata/suricata_global.php");
		$tab_array[] = array(gettext("Update Rules"), false, "/suricata/suricata_download_updates.php");
		$tab_array[] = array(gettext("Alerts"), false, "/suricata/suricata_alerts.php");
		$tab_array[] = array(gettext("Suppress"), false, "/suricata/suricata_suppress.php");
		$tab_array[] = array(gettext("Logs Browser"), false, "/suricata/suricata_logs_browser.php");
		display_top_tabs($tab_array);
	?>
	</td>
</tr>
<tr>
	<td>
	<div id="mainarea">
	<table id="maintable" class="tabcont" width="100%" border="0" cellpadding="0" cellspacing="0">
		<tr id="frheader">
			<td width="3%" class="list">&nbsp;</td>
			<td width="10%" class="listhdrr"><?php echo gettext("Interface"); ?></td>
			<td width="13%" class="listhdrr"><?php echo gettext("Suricata"); ?></td>
			<td width="10%" class="listhdrr"><?php echo gettext("Pattern Match"); ?></td>
			<td width="10%" class="listhdrr"><?php echo gettext("Block"); ?></td>
			<td width="12%" class="listhdrr"><?php echo gettext("Barnyard2"); ?></td>
			<td width="30%" class="listhdr"><?php echo gettext("Description"); ?></td>
			<td width="3%" class="list">
			<table border="0" cellspacing="0" cellpadding="0">
				<tr>
					<td></td>
					<td align="center" valign="middle"><a href="suricata_interfaces_edit.php?id=<?php echo $id_gen;?>"><img
					src="../themes/<?= $g['theme']; ?>/images/icons/icon_plus.gif"
					width="17" height="17" border="0" title="<?php echo gettext('Add Suricata interface mapping');?>"></a></td>
				</tr>
			</table>
			</td>
		</tr>
		<?php $nnats = $i = 0;

		// Turn on buffering to speed up rendering
		ini_set('output_buffering','true');

		// Start buffering to fix display lag issues in IE9 and IE10
		ob_start(null, 0);

		/* If no interfaces are defined, then turn off the "no rules" warning */
		$no_rules_footnote = false;
		if ($id_gen == 0)
			$no_rules = false;
		else
			$no_rules = true;

		foreach ($a_nat as $natent): ?>
		<tr valign="top" id="fr<?=$nnats;?>">
		<?php

			/* convert fake interfaces to real and check if iface is up */
			/* There has to be a smarter way to do this */
			$if_real = get_real_interface($natent['interface']);
			$natend_friendly= convert_friendly_interface_to_friendly_descr($natent['interface']);
			$suricata_uuid = $natent['uuid'];
			if (suricata_is_running($suricata_uuid, $if_real) == 'no'){
				$iconfn = 'block';
				$iconfn_msg1 = 'Suricata is not running on ';
				$iconfn_msg2 = '. Click to start.';
			}
			else{
				$iconfn = 'pass';
				$iconfn_msg1 = 'Suricata is running on ';
				$iconfn_msg2 = '. Click to stop.';
			}
			if (suricata_is_running($suricata_uuid, $if_real, 'barnyard2') == 'no'){
				$biconfn = 'block';
				$biconfn_msg1 = 'Barnyard2 is not running on ';
				$biconfn_msg2 = '. Click to start.';
			}
			else{
				$biconfn = 'pass';
				$biconfn_msg1 = 'Barnyard2 is running on ';
				$biconfn_msg2 = '. Click to stop.';
				}

			/* See if interface has any rules defined and set boolean flag */
			$no_rules = true;
			if (isset($natent['customrules']) && !empty($natent['customrules']))
				$no_rules = false;
			if (isset($natent['rulesets']) && !empty($natent['rulesets']))
				$no_rules = false;
			if (isset($natent['ips_policy']) && !empty($natent['ips_policy']))
				$no_rules = false;
			/* Do not display the "no rules" warning if interface disabled */
			if ($natent['enable'] == "off")
				$no_rules = false;
			if ($no_rules)
				$no_rules_footnote = true;
		?>
			<td class="listt">
			<input type="checkbox" id="frc<?=$nnats;?>" name="rule[]" value="<?=$i;?>" onClick="fr_bgcolor('<?=$nnats;?>')" style="margin: 0; padding: 0;">
			</td>
			<td class="listr" 
			id="frd<?=$nnats;?>" valign="middle" 
			ondblclick="document.location='suricata_interfaces_edit.php?id=<?=$nnats;?>';">
			<?php
				echo $natend_friendly;
			?>
			</td>
			<td class="listr"  
			id="frd<?=$nnats;?>"  
			ondblclick="document.location='suricata_interfaces_edit.php?id=<?=$nnats;?>';">
			<?php
			$check_suricata_info = $config['installedpackages']['suricata']['rule'][$nnats]['enable'];
			if ($check_suricata_info == "on") {
				echo gettext("ENABLED");
				echo "<a href='?act=toggle&id={$i}'>
					<img src='../themes/{$g['theme']}/images/icons/icon_{$iconfn}.gif'
					width='13' height='13' border='0' 
					title='" . gettext($iconfn_msg1.$natend_friendly.$iconfn_msg2) . "'></a>";
				echo ($no_rules) ? "&nbsp;<img src=\"../themes/{$g['theme']}/images/icons/icon_frmfld_imp.png\" width=\"15\" height=\"15\" border=\"0\">" : "";
			} else
				echo gettext("DISABLED");
			?>
			</td>
			<td class="listr" 
			id="frd<?=$nnats;?>" valign="middle" 
			ondblclick="document.location='suricata_interfaces_edit.php?id=<?=$nnats;?>';">
			<?php
			$check_performance_info = $config['installedpackages']['suricata']['rule'][$nnats]['mpm_algo'];
			if ($check_performance_info != "") {
				$check_performance = $check_performance_info;
			}else{
				$check_performance = "unknown";
			}
			?> <?=strtoupper($check_performance);?>
			</td>
			<td class="listr" 
			id="frd<?=$nnats;?>" valign="middle" 
			ondblclick="document.location='suricata_interfaces_edit.php?id=<?=$nnats;?>';">
			<?php
			$check_blockoffenders_info = $config['installedpackages']['suricata']['rule'][$nnats]['blockoffenders'];
			if ($check_blockoffenders_info == "on")
			{
				$check_blockoffenders = enabled;
			} else {
				$check_blockoffenders = disabled;
			}
			?> <?=strtoupper($check_blockoffenders);?>
			</td>
			<td class="listr" 
			id="frd<?=$nnats;?>" valign="middle" 
			ondblclick="document.location='suricata_interfaces_edit.php?id=<?=$nnats;?>';">
			<?php
			$check_suricatabarnyardlog_info = $config['installedpackages']['suricata']['rule'][$nnats]['barnyard_enable'];
			if ($check_suricatabarnyardlog_info == "on") {
				echo gettext("ENABLED");
				echo "<a href='?act=bartoggle&id={$i}'>
					<img src='../themes/{$g['theme']}/images/icons/icon_{$biconfn}.gif'
					width='13' height='13' border='0' 
					title='" . gettext($biconfn_msg1.$natend_friendly.$biconfn_msg2) . "'></a>";
			} else
				echo gettext("DISABLED");
			?>
			</td>
			<td class="listbg" valign="middle" 
			ondblclick="document.location='suricata_interfaces_edit.php?id=<?=$nnats;?>';">
			<font color="#ffffff"> <?=htmlspecialchars($natent['descr']);?>&nbsp;</font>
			</td>
			<td valign="middle" class="list" nowrap>
			<table border="0" cellspacing="0" cellpadding="0">
				<tr>
					<td><a href="suricata_interfaces_edit.php?id=<?=$i;?>"><img
						src="/themes/<?= $g['theme']; ?>/images/icons/icon_e.gif"
						width="17" height="17" border="0" title="<?php echo gettext('Edit Suricata interface mapping'); ?>"></a>
					</td>
				</tr>
			</table>
			</td>	
		</tr>
		<?php $i++; $nnats++; endforeach; ob_end_flush(); ?>
		<tr>
			<td class="list"></td>
			<td class="list" colspan="6">
				<?php if ($no_rules_footnote): ?><br><img src="../themes/<?= $g['theme']; ?>/images/icons/icon_frmfld_imp.png" width="15" height="15" border="0">
					<span class="red">&nbsp;&nbsp <?php echo gettext("WARNING: Marked interface currently has no rules defined for Suricata"); ?></span>
				<?php else: ?>&nbsp;
				<?php endif; ?>					 
			</td>
			<td class="list" valign="middle" nowrap>
				<table border="0" cellspacing="0" cellpadding="0">
					<tr>
						<td><?php if ($nnats == 0): ?><img
						src="../themes/<?= $g['theme']; ?>/images/icons/icon_x_d.gif"
						width="17" height="17" " border="0">
						<?php else: ?>
						<input name="del" type="image"
						src="../themes/<?= $g['theme']; ?>/images/icons/icon_x.gif"
						width="17" height="17" title="<?php echo gettext("Delete selected Suricata interface mapping(s)"); ?>"
						onclick="return intf_del()">
						<?php endif; ?></td>
					</tr>
				</table>
			</td>
		</tr>
		<tr>
		<td colspan="8">&nbsp;</td>
		</tr>
		<tr>
			<td>&nbsp;</td>
			<td colspan="6">
			<table class="tabcont" width="100%" border="0" cellpadding="1" cellspacing="0">
				<tr>
					<td colspan="3" class="vexpl"><span class="red"><strong><?php echo gettext("Note:"); ?></strong></span> <br>
						<?php echo gettext("This is the ") . "<strong>" . gettext("Suricata Menu ") . 
						"</strong>" . gettext("where you can see an overview of all your interface settings.  ");
						if (empty($a_nat)) {
							echo gettext("Please configure the parameters on the ") . "<strong>" . gettext("Global Settings") . 
							"</strong>" . gettext(" tab before adding an interface."); 
						}?>
					</td>
				</tr>
				<tr>
					<td colspan="3" class="vexpl"><br>
					</td>
				</tr>
				<tr>
					<td colspan="3" class="vexpl"><span class="red"><strong><?php echo gettext("Warning:"); ?></strong></span><br>
						<strong><?php echo gettext("New settings will not take effect until interface restart."); ?></strong>
					</td>
				</tr>
				<tr>
					<td colspan="3" class="vexpl"><br>
					</td>
				</tr>
				<tr>
					<td class="vexpl"><strong>Click</strong> on the <img src="../themes/<?= $g['theme']; ?>/images/icons/icon_plus.gif"
						width="17" height="17" border="0" title="<?php echo gettext("Add Icon"); ?>"> icon to add 
						an interface.
					</td>
					<td width="3%" class="vexpl">&nbsp;
					</td>
					<td class="vexpl"><img src="../themes/<?= $g['theme']; ?>/images/icons/icon_pass.gif"
						width="13" height="13" border="0" title="<?php echo gettext("Running"); ?>">
						<img src="../themes/<?= $g['theme']; ?>/images/icons/icon_block.gif"
						width="13" height="13" border="0" title="<?php echo gettext("Not Running"); ?>">  icons will show current 
						suricata and barnyard2 status.
					</td>
				</tr>
				<tr>
					<td class="vexpl"><strong>Click</strong> on the <img src="../themes/<?= $g['theme']; ?>/images/icons/icon_e.gif"
						width="17" height="17" border="0" title="<?php echo gettext("Edit Icon"); ?>"> icon to edit 
						an interface and settings.
					<td width="3%">&nbsp;
					</td>
					<td class="vexpl"><strong>Click</strong> on the status icons to <strong>toggle</strong> suricata and barnyard2 status.
					</td>
				</tr>
				<tr>
					<td colspan="3" class="vexpl"><strong> Click</strong> on the <img src="../themes/<?= $g['theme']; ?>/images/icons/icon_x.gif"
						width="17" height="17" border="0" title="<?php echo gettext("Delete Icon"); ?>"> icon to
						delete an interface and settings.
					</td>
				</tr>
			</table>
			</td>
			<td>&nbsp;</td>
		</tr>
	</table>
	</div>
	</td>
</tr>
</table>
</form>

<script type="text/javascript">

function intf_del() {
	var isSelected = false;
	var inputs = document.iform.elements;
	for (var i = 0; i < inputs.length; i++) {
		if (inputs[i].type == "checkbox") {
			if (inputs[i].checked)
				isSelected = true;
		}
	}
	if (isSelected)
		return confirm('Do you really want to delete the selected Suricata mapping?');
	else
		alert("There is no Suricata mapping selected for deletion.  Click the checkbox beside the Suricata mapping(s) you wish to delete.");
}

</script>

<?php
include("fend.inc");
?>
</body>
</html>
