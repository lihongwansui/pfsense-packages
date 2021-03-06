<?php
/*
 * suricata_barnyard.php
 * part of pfSense
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

require_once("guiconfig.inc");
require_once("/usr/local/pkg/suricata/suricata.inc");

global $g, $rebuild_rules;

$id = $_GET['id'];
if (isset($_POST['id']))
	$id = $_POST['id'];
if (is_null($id)) {
        header("Location: /suricata/suricata_interfaces.php");
        exit;
}

if (!is_array($config['installedpackages']['suricata']['rule']))
	$config['installedpackages']['suricata']['rule'] = array();
$a_nat = &$config['installedpackages']['suricata']['rule'];

$pconfig = array();
if (isset($id) && $a_nat[$id]) {
	/* old options */
	$pconfig = $a_nat[$id];
	if (!empty($a_nat[$id]['barnconfigpassthru']))
		$pconfig['barnconfigpassthru'] = base64_decode($a_nat[$id]['barnconfigpassthru']);
	if (!empty($a_nat[$id]['barnyard_dbpwd']))
		$pconfig['barnyard_dbpwd'] = base64_decode($a_nat[$id]['barnyard_dbpwd']);
	if (empty($a_nat[$id]['barnyard_show_year']))
		$pconfig['barnyard_show_year'] = "on";
	if (empty($a_nat[$id]['barnyard_archive_enable']))
		$pconfig['barnyard_archive_enable'] = "on";
	if (empty($a_nat[$id]['barnyard_obfuscate_ip']))
		$pconfig['barnyard_obfuscate_ip'] = "off";
	if (empty($a_nat[$id]['barnyard_syslog_dport']))
		$pconfig['barnyard_syslog_dport'] = "514";
	if (empty($a_nat[$id]['barnyard_syslog_proto']))
		$pconfig['barnyard_syslog_proto'] = "udp";
	if (empty($a_nat[$id]['barnyard_syslog_opmode']))
		$pconfig['barnyard_syslog_opmode'] = "default";
	if (empty($a_nat[$id]['barnyard_syslog_facility']))
		$pconfig['barnyard_syslog_facility'] = "LOG_USER";
	if (empty($a_nat[$id]['barnyard_syslog_priority']))
		$pconfig['barnyard_syslog_priority'] = "LOG_INFO";
	if (empty($a_nat[$id]['barnyard_sensor_name']))
		$pconfig['barnyard_sensor_name'] = php_uname("n");
}

if (isset($_GET['dup']))
	unset($id);

if ($_POST) {

	foreach ($a_nat as $natent) {
		if (isset($id) && ($a_nat[$id]) && ($a_nat[$id] === $natent))
			continue;
		if ($natent['interface'] != $_POST['interface'])
			$input_error[] = "This interface has already an instance defined";
	}

	// Check that at least one output plugin is enabled
	if ($_POST['barnyard_mysql_enable'] != 'on' && $_POST['barnyard_syslog_enable'] != 'on')
		$input_errors[] = gettext("You must enable at least one output option when using Barnyard2.");

	// Validate inputs if MySQL database loggging enabled
	if ($_POST['barnyard_mysql_enable'] == 'on') {
		if (empty($_POST['barnyard_dbhost']))
			$input_errors[] = gettext("Please provide a valid hostname or IP address for the MySQL database host.");
		if (empty($_POST['barnyard_dbname']))
			$input_errors[] = gettext("You must provide a DB instance name when logging to a MySQL database.");
		if (empty($_POST['barnyard_dbuser']))
			$input_errors[] = gettext("You must provide a DB user login name when logging to a MySQL database.");
	}

	// Validate inputs if syslog output enabled
	if ($_POST['barnyard_syslog_enable'] == 'on' && $_POST['barnyard_syslog_local'] <> 'on') {
		if (empty($_POST['barnyard_syslog_dport']) || !is_numeric($_POST['barnyard_syslog_dport']))
			$input_errors[] = gettext("Please provide a valid number between 1 and 65535 for the Syslog Remote Port.");
		if (empty($_POST['barnyard_syslog_rhost']))
			$input_errors[] = gettext("Please provide a valid hostname or IP address for the Syslog Remote Host.");
	}

	// if no errors write to conf
	if (!$input_errors) {
		$natent = array();
		/* repost the options already in conf */
		$natent = $pconfig;

		$natent['barnyard_enable'] = $_POST['barnyard_enable'] ? 'on' : 'off';
		$natent['barnyard_show_year'] = $_POST['barnyard_show_year'] ? 'on' : 'off';
		$natent['barnyard_archive_enable'] = $_POST['barnyard_archive_enable'] ? 'on' : 'off';
		$natent['barnyard_dump_payload'] = $_POST['barnyard_dump_payload'] ? 'on' : 'off';
		$natent['barnyard_obfuscate_ip'] = $_POST['barnyard_obfuscate_ip'] ? 'on' : 'off';
		$natent['barnyard_mysql_enable'] = $_POST['barnyard_mysql_enable'] ? 'on' : 'off';
		$natent['barnyard_syslog_enable'] = $_POST['barnyard_syslog_enable'] ? 'on' : 'off';
		$natent['barnyard_syslog_local'] = $_POST['barnyard_syslog_local'] ? 'on' : 'off';
		$natent['barnyard_syslog_opmode'] = $_POST['barnyard_syslog_opmode'];
		$natent['barnyard_syslog_proto'] = $_POST['barnyard_syslog_proto'];

		if ($_POST['barnyard_sensor_name']) $natent['barnyard_sensor_name'] = $_POST['barnyard_sensor_name']; else unset($natent['barnyard_sensor_name']);
		if ($_POST['barnyard_dbhost']) $natent['barnyard_dbhost'] = $_POST['barnyard_dbhost']; else unset($natent['barnyard_dbhost']);
		if ($_POST['barnyard_dbname']) $natent['barnyard_dbname'] = $_POST['barnyard_dbname']; else unset($natent['barnyard_dbname']);
		if ($_POST['barnyard_dbuser']) $natent['barnyard_dbuser'] = $_POST['barnyard_dbuser']; else unset($natent['barnyard_dbuser']);
		if ($_POST['barnyard_dbpwd']) $natent['barnyard_dbpwd'] = base64_encode($_POST['barnyard_dbpwd']); else unset($natent['barnyard_dbpwd']);
		if ($_POST['barnyard_syslog_rhost']) $natent['barnyard_syslog_rhost'] = $_POST['barnyard_syslog_rhost']; else unset($natent['barnyard_syslog_rhost']);
		if ($_POST['barnyard_syslog_dport']) $natent['barnyard_syslog_dport'] = $_POST['barnyard_syslog_dport']; else $natent['barnyard_syslog_dport'] = '514';
		if ($_POST['barnyard_syslog_facility']) $natent['barnyard_syslog_facility'] = $_POST['barnyard_syslog_facility']; else $natent['barnyard_syslog_facility'] = 'LOG_USER';
		if ($_POST['barnyard_syslog_priority']) $natent['barnyard_syslog_priority'] = $_POST['barnyard_syslog_priority']; else $natent['barnyard_syslog_priority'] = 'LOG_INFO';
		if ($_POST['barnconfigpassthru']) $natent['barnconfigpassthru'] = base64_encode($_POST['barnconfigpassthru']); else unset($natent['barnconfigpassthru']);

		if (isset($id) && $a_nat[$id])
			$a_nat[$id] = $natent;
		else {
			$a_nat[] = $natent;
		}

		write_config();

		// No need to rebuild rules if just toggling Barnyard2 on or off
		$rebuild_rules = false;
		sync_suricata_package_config();

		// Signal any running barnyard2 instance on this interface to
		// reload its configuration to pick up any changes made.
		suricata_barnyard_reload_config($a_nat[$id], "HUP");

		// after click go to this page
		header( 'Expires: Sat, 26 Jul 1997 05:00:00 GMT' );
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s' ) . ' GMT' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate' );
		header( 'Cache-Control: post-check=0, pre-check=0', false );
		header( 'Pragma: no-cache' );
		header("Location: suricata_barnyard.php?id=$id");
		exit;
	}
}

$if_friendly = convert_friendly_interface_to_friendly_descr($pconfig['interface']);
$pgtitle = gettext("Suricata: Interface {$if_friendly} - Barnyard2 Settings");
include_once("head.inc");

?>
<body link="#0000CC" vlink="#0000CC" alink="#0000CC">

<?php include("fbegin.inc"); ?>
<?if($pfsense_stable == 'yes'){echo '<p class="pgtitle">' . $pgtitle . '</p>';}?>

<?php
	/* Display Alert message */
	if ($input_errors) {
		print_input_errors($input_errors); // TODO: add checks
	}

	if ($savemsg) {
		print_info_box($savemsg);
	}

	?>

<form action="suricata_barnyard.php" method="post"
	enctype="multipart/form-data" name="iform" id="iform">
<table width="100%" border="0" cellpadding="0" cellspacing="0">
<tr><td>
<?php
    $tab_array = array();
	$tab_array[] = array(gettext("Suricata Interfaces"), true, "/suricata/suricata_interfaces.php");
	$tab_array[] = array(gettext("Global Settings"), false, "/suricata/suricata_global.php");
	$tab_array[] = array(gettext("Update Rules"), false, "/suricata/suricata_download_updates.php");
	$tab_array[] = array(gettext("Alerts"), false, "/suricata/suricata_alerts.php?instance={$id}");
	$tab_array[] = array(gettext("Suppress"), false, "/suricata/suricata_suppress.php");
	$tab_array[] = array(gettext("Logs Browser"), false, "/suricata/suricata_logs_browser.php");
	display_top_tabs($tab_array);
	echo '</td></tr>';
	echo '<tr><td class="tabnavtbl">';
	$tab_array = array();
	$menu_iface=($if_friendly?substr($if_friendly,0,5)." ":"Iface ");
	$tab_array[] = array($menu_iface . gettext("Settings"), false, "/suricata/suricata_interfaces_edit.php?id={$id}");
	$tab_array[] = array($menu_iface . gettext("Categories"), false, "/suricata/suricata_rulesets.php?id={$id}");
	$tab_array[] = array($menu_iface . gettext("Rules"), false, "/suricata/suricata_rules.php?id={$id}");
        $tab_array[] = array($menu_iface . gettext("Flow/Stream"), false, "/suricata/suricata_flow_stream.php?id={$id}");
	$tab_array[] = array($menu_iface . gettext("App Parsers"), false, "/suricata/suricata_app_parsers.php?id={$id}");
	$tab_array[] = array($menu_iface . gettext("Variables"), false, "/suricata/suricata_define_vars.php?id={$id}");
	$tab_array[] = array($menu_iface . gettext("Barnyard2"), true, "/suricata/suricata_barnyard.php?id={$id}");
        display_top_tabs($tab_array);
?>
</td></tr>
	<tr>
		<td><div id="mainarea">
		<table id="maintable" class="tabcont" width="100%" border="0" cellpadding="6" cellspacing="0">
			<tr>
				<td colspan="2" valign="top" class="listtopic"><?php echo gettext("General Barnyard2 " .
				"Settings"); ?></td>
			</tr>
			<tr>
				<td width="22%" valign="top" class="vncellreq"><?php echo gettext("Enable"); ?></td>
				<td width="78%" class="vtable">
					<input name="barnyard_enable" type="checkbox" value="on" <?php if ($pconfig['barnyard_enable'] == "on") echo "checked"; ?>  onClick="enable_change(false)"/>
					<strong><?php echo gettext("Enable Barnyard2"); ?></strong><br/>
					<?php echo gettext("This will enable barnyard2 for this interface. You will also to enable at least one logging destination below."); ?></td>
			</tr>
			<tr>
				<td width="22%" valign="top" class="vncell"><?php echo gettext("Show Year"); ?></td>
				<td width="78%" class="vtable">
					<input name="barnyard_show_year" type="checkbox" value="on" <?php if ($pconfig['barnyard_show_year'] == "on") echo "checked"; ?>/>
					<?php echo gettext("Enable the year being shown in timestamps.  Default value is ") . "<strong>" . gettext("Checked") . "</strong>"; ?>
				</td>
			</tr>
			<tr>
				<td width="22%" valign="top" class="vncell"><?php echo gettext("Archive Unified2 Logs"); ?></td>
				<td width="78%" class="vtable">
					<input name="barnyard_archive_enable" type="checkbox" value="on" <?php if ($pconfig['barnyard_archive_enable'] == "on") echo "checked"; ?>/>
					<?php echo gettext("Enable the archiving of processed unified2 log files.  Default value is ") . "<strong>" . gettext("Checked") . "</strong>"; ?><br/>
					<?php echo gettext("Unified2 log files will be moved to an archive folder for subsequent cleanup when processed."); ?>
				</td>
			</tr>
			<tr>
				<td width="22%" valign="top" class="vncell"><?php echo gettext("Dump Payload"); ?></td>
				<td width="78%" class="vtable">
					<input name="barnyard_dump_payload" type="checkbox" value="on" <?php if ($pconfig['barnyard_dump_payload'] == "on") echo "checked"; ?>/>
					<?php echo gettext("Enable dumping of application data from unified2 files.  Default value is ") . "<strong>" . gettext("Not Checked") . "</strong>"; ?><br/>
				</td>
			</tr>
			<tr>
				<td width="22%" valign="top" class="vncell"><?php echo gettext("Obfuscate IP Addresses"); ?></td>
				<td width="78%" class="vtable">
					<input name="barnyard_obfuscate_ip" type="checkbox" value="on" <?php if ($pconfig['barnyard_obfuscate_ip'] == "on") echo "checked"; ?>/>
					<?php echo gettext("Enable obfuscation of logged IP addresses.  Default value is ") . "<strong>" . gettext("Not Checked") . "</strong>"; ?>
				</td>
			<tr>
				<td width="22%" valign="top" class="vncell"><?php echo gettext("Sensor Name"); ?></td>
				<td width="78%" class="vtable">
					<input name="barnyard_sensor_name" type="text" class="formfld unknown" 
					id="barnyard_sensor_name" size="25" value="<?=htmlspecialchars($pconfig['barnyard_sensor_name']);?>"/>
					&nbsp;<?php echo gettext("Unique name to use for this sensor."); ?>
				</td>
			</tr>
			</tr>
			<tr>
				<td colspan="2" valign="top" class="listtopic"><?php echo gettext("MySQL Database Output Settings"); ?></td>
			</tr>
			<tr>
				<td width="22%" valign="top" class="vncell"><?php echo gettext("Enable MySQL Database"); ?></td>
				<td width="78%" class="vtable">
					<input name="barnyard_mysql_enable" type="checkbox" value="on" <?php if ($pconfig['barnyard_mysql_enable'] == "on") echo "checked"; ?> 
					onClick="toggle_mySQL()"/><?php echo gettext("Enable logging of alerts to a MySQL database instance"); ?><br/>
					<?php echo gettext("You will also have to provide the database credentials in the fields below."); ?></td>
			</tr>
			<tr>
				<td width="22%" valign="top" class="vncell"><?php echo gettext("Database Host"); ?></td>
				<td width="78%" class="vtable">
					<input name="barnyard_dbhost" type="text" class="formfld host" 
					id="barnyard_dbhost" size="25" value="<?=htmlspecialchars($pconfig['barnyard_dbhost']);?>"/>
					&nbsp;<?php echo gettext("Hostname or IP address of the MySQL database server"); ?>
				</td>
			</tr>
			<tr>
				<td width="22%" valign="top" class="vncell"><?php echo gettext("Database Name"); ?></td>
				<td width="78%" class="vtable">
					<input name="barnyard_dbname" type="text" class="formfld unknown" 
					id="barnyard_dbname" size="25" value="<?=htmlspecialchars($pconfig['barnyard_dbname']);?>"/>
					&nbsp;<?php echo gettext("Instance or DB name of the MySQL database"); ?>
				</td>
			</tr>
			<tr>
				<td width="22%" valign="top" class="vncell"><?php echo gettext("Database User Name"); ?></td>
				<td width="78%" class="vtable">
					<input name="barnyard_dbuser" type="text" class="formfld user" 
					id="barnyard_dbuser" size="25" value="<?=htmlspecialchars($pconfig['barnyard_dbuser']);?>"/>
					&nbsp;<?php echo gettext("Username for the MySQL database"); ?>
				</td>
			</tr>
			<tr>
				<td width="22%" valign="top" class="vncell"><?php echo gettext("Database User Password"); ?></td>
				<td width="78%" class="vtable">
					<input name="barnyard_dbpwd" type="password" class="formfld pwd" 
					id="barnyard_dbpwd" size="25" value="<?=htmlspecialchars($pconfig['barnyard_dbpwd']);?>"/>
					&nbsp;<?php echo gettext("Password for the MySQL database user"); ?>
				</td>
			</tr>
			<tr>
				<td colspan="2" valign="top" class="listtopic"><?php echo gettext("Syslog Output Settings"); ?></td>
			</tr>
			<tr>
				<td width="22%" valign="top" class="vncell"><?php echo gettext("Enable Syslog"); ?></td>
				<td width="78%" class="vtable">
					<input name="barnyard_syslog_enable" type="checkbox" value="on" <?php if ($pconfig['barnyard_syslog_enable'] == "on") echo "checked"; ?> 
					onClick="toggle_syslog()"/>
					<?php echo gettext("Enable logging of alerts to a syslog receiver"); ?><br/>
					<?php echo gettext("This will send alert data to either a local or remote syslog receiver."); ?></td>
			</tr>
			<tr>
				<td width="22%" valign="top" class="vncell"><?php echo gettext("Operation Mode"); ?></td>
				<td width="78%" class="vtable">
					<input name="barnyard_syslog_opmode" type="radio" id="barnyard_syslog_opmode_default"  
					value="default" <?php if ($pconfig['barnyard_syslog_opmode'] == 'default') echo "checked";?>/>
					<?php echo gettext("DEFAULT"); ?>&nbsp;<input name="barnyard_syslog_opmode" type="radio" id="barnyard_syslog_opmode_complete" 
					value="complete" <?php if ($pconfig['barnyard_syslog_opmode'] == 'complete') echo "checked";?>/>
					<?php echo gettext("COMPLETE"); ?>&nbsp;&nbsp;
					<?php echo gettext("Select the level of detail to include when reporting"); ?><br/><br/>
					<?php echo gettext("DEFAULT mode is compatible with the standard Snort syslog format.  COMPLETE mode includes additional information such as the raw packet data (displayed in hex format)."); ?>
				</td>
			</tr>
			<tr>
				<td width="22%" valign="top" class="vncell"><?php echo gettext("Local Only"); ?></td>
				<td width="78%" class="vtable">
					<input name="barnyard_syslog_local" type="checkbox" value="on" <?php if ($pconfig['barnyard_syslog_local'] == "on") echo "checked"; ?> 
					onClick="toggle_local_syslog()"/>
					<?php echo gettext("Enable logging of alerts to the local system only"); ?><br/>
					<?php echo gettext("This will send alert data to the local system only and overrides the host, port, protocol, facility and priority values below."); ?></td>
			</tr>
			<tr>
				<td width="22%" valign="top" class="vncell"><?php echo gettext("Remote Host"); ?></td>
				<td width="78%" class="vtable">
					<input name="barnyard_syslog_rhost" type="text" class="formfld host" 
					id="barnyard_syslog_rhost" size="25" value="<?=htmlspecialchars($pconfig['barnyard_syslog_rhost']);?>"/>
					&nbsp;<?php echo gettext("Hostname or IP address of remote syslog host"); ?>
				</td>
			</tr>
			<tr>
				<td width="22%" valign="top" class="vncell"><?php echo gettext("Remote Port"); ?></td>
				<td width="78%" class="vtable">
					<input name="barnyard_syslog_dport" type="text" class="formfld unknown" 
					id="barnyard_syslog_dport" size="25" value="<?=htmlspecialchars($pconfig['barnyard_syslog_dport']);?>"/>
					&nbsp;<?php echo gettext("Port number for syslog on remote host.  Default is ") . "<strong>" . gettext("514") . "</strong>."; ?>
				</td>
			</tr>
			<tr>
				<td width="22%" valign="top" class="vncell"><?php echo gettext("Protocol"); ?></td>
				<td width="78%" class="vtable">
					<input name="barnyard_syslog_proto" type="radio" id="barnyard_syslog_proto_udp"  
					value="udp" <?php if ($pconfig['barnyard_syslog_proto'] == 'udp') echo "checked";?>/>
					<?php echo gettext("UDP"); ?>&nbsp;<input name="barnyard_syslog_proto" type="radio" id="barnyard_syslog_proto_tcp" 
					value="tcp" <?php if ($pconfig['barnyard_syslog_proto'] == 'tcp') echo "checked";?>/>
					<?php echo gettext("TCP"); ?>&nbsp;&nbsp;
					<?php echo gettext("Select IP protocol to use for remote reporting.  Default is ") . "<strong>" . gettext("UDP") . "</strong>."; ?>
				</td>
			</tr>
			<tr>
				<td width="22%" valign="top" class="vncell"><?php echo gettext("Log Facility"); ?></td>
				<td width="78%" class="vtable">
					<select name="barnyard_syslog_facility" id="barnyard_syslog_facility" class="formselect">
					<?php
						$log_facility = array(  "LOG_AUTH", "LOG_AUTHPRIV", "LOG_DAEMON", "LOG_KERN", "LOG_SYSLOG", "LOG_USER", "LOG_LOCAL1",
									"LOG_LOCAL2", "LOG_LOCAL3", "LOG_LOCAL4", "LOG_LOCAL5", "LOG_LOCAL6", "LOG_LOCAL7" );
						foreach ($log_facility as $facility) {
							$selected = "";
							if ($facility == $pconfig['barnyard_syslog_facility'])
								$selected = " selected";
							echo "<option value='{$facility}'{$selected}>" . $facility . "</option>\n";
						}
					?></select>&nbsp;&nbsp;
					<?php echo gettext("Select Syslog Facility to use for remote reporting.  Default is ") . "<strong>" . gettext("LOG_USER") . "</strong>."; ?>
				</td>
			</tr>
			<tr>
				<td width="22%" valign="top" class="vncell"><?php echo gettext("Log Priority"); ?></td>
				<td width="78%" class="vtable">
					<select name="barnyard_syslog_priority" id="barnyard_syslog_priority" class="formselect">
					<?php
						$log_priority = array( "LOG_EMERG", "LOG_ALERT", "LOG_CRIT", "LOG_ERR", "LOG_WARNING", "LOG_NOTICE", "LOG_INFO" );
						foreach ($log_priority as $priority) {
							$selected = "";
							if ($priority == $pconfig['barnyard_syslog_priority'])
								$selected = " selected";
							echo "<option value='{$priority}'{$selected}>" . $priority . "</option>\n";
						}
					?></select>&nbsp;&nbsp;
					<?php echo gettext("Select Syslog Priority (Level) to use for remote reporting.  Default is ") . "<strong>" . gettext("LOG_INFO") . "</strong>."; ?>
				</td>
			</tr>
			<tr>
				<td colspan="2" valign="top" class="listtopic"><?php echo gettext("Advanced Settings"); ?></td>
			</tr>
			<tr>
				<td width="22%" valign="top" class="vncell"><?php echo gettext("Advanced configuration " .
				"pass through"); ?></td>
				<td width="78%" class="vtable"><textarea name="barnconfigpassthru" style="width:95%;"
					cols="65" rows="7" id="barnconfigpassthru" ><?=htmlspecialchars($pconfig['barnconfigpassthru']);?></textarea>
				<br/>
				<?php echo gettext("Arguments entered here will be automatically inserted into the running " .
				"barnyard2 configuration."); ?></td>
			</tr>
			<tr>
				<td width="22%" valign="top">&nbsp;</td>
				<td width="78%">
					<input name="Submit" type="submit" class="formbtn" value="Save">
					<input name="id" type="hidden" value="<?=$id;?>"> </td>
			</tr>
			<tr>
				<td width="22%" valign="top">&nbsp;</td>
				<td width="78%"><span class="vexpl"><span class="red"><strong><?php echo gettext("Note:"); ?></strong></span></span>
				<br/>
				<?php echo gettext("Please save your settings before you click start."); ?> </td>
			</tr>
		</table>
		</div>
		</td>
	</tr>
</table>
</form>

<script language="JavaScript">

function toggle_mySQL() {
	var endis = !document.iform.barnyard_mysql_enable.checked;

	document.iform.barnyard_dbhost.disabled = endis;
	document.iform.barnyard_dbname.disabled = endis;
	document.iform.barnyard_dbuser.disabled = endis;
	document.iform.barnyard_dbpwd.disabled = endis;
}

function toggle_syslog() {
	var endis = !document.iform.barnyard_syslog_enable.checked;

	document.iform.barnyard_syslog_opmode_default.disabled = endis;
	document.iform.barnyard_syslog_opmode_complete.disabled = endis;
	document.iform.barnyard_syslog_local.disabled = endis;
	document.iform.barnyard_syslog_rhost.disabled = endis;
	document.iform.barnyard_syslog_dport.disabled = endis;
	document.iform.barnyard_syslog_proto_udp.disabled = endis;
	document.iform.barnyard_syslog_proto_tcp.disabled = endis;
	document.iform.barnyard_syslog_facility.disabled = endis;
	document.iform.barnyard_syslog_priority.disabled = endis;
}

function toggle_local_syslog() {
	var endis = document.iform.barnyard_syslog_local.checked;

	if (document.iform.barnyard_syslog_enable.checked) {
		document.iform.barnyard_syslog_rhost.disabled = endis;
		document.iform.barnyard_syslog_dport.disabled = endis;
		document.iform.barnyard_syslog_proto_udp.disabled = endis;
		document.iform.barnyard_syslog_proto_tcp.disabled = endis;
		document.iform.barnyard_syslog_facility.disabled = endis;
		document.iform.barnyard_syslog_priority.disabled = endis;
	}
}

function enable_change(enable_change) {
	endis = !(document.iform.barnyard_enable.checked || enable_change);
	// make sure a default answer is called if this is invoked.
	endis2 = (document.iform.barnyard_enable);
	document.iform.barnyard_archive_enable.disabled = endis;
	document.iform.barnyard_show_year.disabled = endis;
	document.iform.barnyard_dump_payload.disabled = endis;
	document.iform.barnyard_obfuscate_ip.disabled = endis;
	document.iform.barnyard_sensor_name.disabled = endis;
	document.iform.barnyard_mysql_enable.disabled = endis;
	document.iform.barnyard_dbhost.disabled = endis;
	document.iform.barnyard_dbname.disabled = endis;
	document.iform.barnyard_dbuser.disabled = endis;
	document.iform.barnyard_dbpwd.disabled = endis;
	document.iform.barnyard_syslog_enable.disabled = endis;
	document.iform.barnyard_syslog_local.disabled = endis;
	document.iform.barnyard_syslog_opmode_default.disabled = endis;
	document.iform.barnyard_syslog_opmode_complete.disabled = endis;
	document.iform.barnyard_syslog_rhost.disabled = endis;
	document.iform.barnyard_syslog_dport.disabled = endis;
	document.iform.barnyard_syslog_proto_udp.disabled = endis;
	document.iform.barnyard_syslog_proto_tcp.disabled = endis;
	document.iform.barnyard_syslog_facility.disabled = endis;
	document.iform.barnyard_syslog_priority.disabled = endis;
	document.iform.barnconfigpassthru.disabled = endis;
}

enable_change(false);
toggle_mySQL();
toggle_syslog();
toggle_local_syslog();

</script>

<?php include("fend.inc"); ?>
</body>
</html>
