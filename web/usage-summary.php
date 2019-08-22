<?php
# Copyright 2007, 2008, 2016, 2017 Ohio Supercomputer Center
# Copyright 2008, 2009, 2010, 2011, 2014 University of Tennessee
# Revision info:
# $HeadURL$
# $Revision$
# $Date$
require_once 'dbutils.php';
require_once 'page-layout.php';
require_once 'metrics.php';
require_once 'site-specific.php';

# accept get queries too for handy command-line usage:  suck all the
# parameters into _POST.
if (isset($_GET['system']))
  {
    $_POST = $_GET;
  }

# connect to DB
$db = db_connect();

# list of software packages
#$packages=software_list($db);

# regular expressions for different software packages
#$pkgmatch=software_match_list($db);

$title = "Usage summary";
if ( isset($_POST['system']) )
  {
    $title .= " for ".$_POST['system'];
    $verb = title_verb($_POST['datelogic']);
    if ( isset($_POST['start_date']) && isset($_POST['end_date']) &&
	 $_POST['start_date']==$_POST['end_date'] && 
	 $_POST['start_date']!="" )
      {
	$title .= " ".$verb." on ".$_POST['start_date'];
      }
    else if ( isset($_POST['start_date']) && isset($_POST['end_date']) && $_POST['start_date']!=$_POST['end_date'] && 
	      $_POST['start_date']!="" &&  $_POST['end_date']!="" )
      {
	$title .= " ".$verb." between ".$_POST['start_date']." and ".$_POST['end_date'];
      }
    else if ( isset($_POST['start_date']) && $_POST['start_date']!="" )
      {
	$title .= " ".$verb." after ".$_POST['start_date'];
      }
    else if ( isset($_POST['end_date']) && $_POST['end_date']!="" )
      {
	$title .= " ".$verb." before ".$_POST['end_date'];
      }
  }
page_header($title);


if ( isset($_POST['system']) )
  {
    # system overview
    echo "<H3>Overview</H3>\n";
    $sql = "SELECT system, COUNT(jobid) AS jobs, SUM(".cpuhours($db,$_POST['system'],$_POST['start_date'],$_POST['end_date'],$_POST['datelogic']).") AS cpuhours, SUM(".gpuhours($db,$_POST['system'],$_POST['start_date'],$_POST['end_date'],$_POST['datelogic']).") AS gpuhours, SUM(".nodehours($db,$_POST['system'],$_POST['start_date'],$_POST['end_date'],$_POST['datelogic']).") AS nodehours, SUM(".charges($db,$_POST['system'],$_POST['start_date'],$_POST['end_date'],$_POST['datelogic']).") AS charges, NULL AS pct_util, COUNT(DISTINCT(username)) AS users, COUNT(DISTINCT(groupname)) AS groups, COUNT(DISTINCT(account)) AS accounts FROM Jobs WHERE ( ".sysselect($_POST['system'])." ) AND ( ".dateselect($_POST['datelogic'],$_POST['start_date'],$_POST['end_date'])." ) GROUP BY system ORDER BY ".$_POST['order']." DESC";
    #echo "<PRE>\n".$sql."</PRE>\n";
    echo "<TABLE border=1>\n";
    echo "<TR><TH>system</TH><TH>jobs</TH><TH>cpuhours</TH><TH>gpuhours</TH><TH>nodehours</TH><TH>charges</TH><TH>%cpuutil</TH><TH>users</TH><TH>groups</TH><TH>accounts</TH></TR>\n";
    ob_flush();
    flush();

    $result = db_query($db,$sql);
    if ( PEAR::isError($result) )
      {
        echo "<PRE>".$result->getMessage()."</PRE>\n";
      }
    while ($result->fetchInto($row))
      {
	$data=array();
	$rkeys=array_keys($row);
	echo "<TR>";
	foreach ($rkeys as $rkey)
	  {
	    if ( $row[$rkey]==NULL )
	      {
		$ndays=ndays($db,$row[0],$_POST['start_date'],$_POST['end_date']);
		if ( $ndays[1]>0 )
		  {
		    $data[$rkey]=sprintf("%6.2f",100.0*$row[2]/$ndays[1]);
		  }
		else
		  {
		    $data[$rkey]="N/A";
		  }
	      }
	    else
	      {
		$data[$rkey]=$row[$rkey];
	      }
	    # if a float, format appropriately
	    if ( preg_match("/^-?\d*\.\d+$/",$data[$rkey])==1 )
	      {
		echo "<TD align=\"right\"><PRE>".number_format(floatval($data[$rkey]),4)."</PRE></TD>";
	      }
            # if an int, format appropriately
	    else if ( preg_match("/^-?\d+$/",$data[$rkey])==1 )
	      {
		echo "<TD align=\"right\"><PRE>".number_format(floatval($data[$rkey]))."</PRE></TD>";
	      }
            # otherwise print verbatim
	    else
	      {
		echo "<TD align=\"right\"><PRE>".$data[$rkey]."</PRE></TD>";
	      }
	  }
	echo "</TR>\n";
	ob_flush();
	flush();
      }
    if ( $_POST['system']=="%" )
      {
	$sql = "SELECT 'TOTAL', COUNT(jobid) AS jobs, SUM(".cpuhours($db,$_POST['system'],$_POST['start_date'],$_POST['end_date'],$_POST['datelogic']).") AS cpuhours, SUM(".gpuhours($db,$_POST['system'],$_POST['start_date'],$_POST['end_date'],$_POST['datelogic']).") AS gpuhours, SUM(".nodehours($db,$_POST['system'],$_POST['start_date'],$_POST['end_date'],$_POST['datelogic']).") AS nodehours, SUM(".charges($db,$_POST['system'],$_POST['start_date'],$_POST['end_date'],$_POST['datelogic']).") AS charges, 'N/A' AS pct_util, COUNT(DISTINCT(username)) AS users, COUNT(DISTINCT(groupname)) AS groups, COUNT(DISTINCT(account)) AS accounts FROM Jobs WHERE ( ".sysselect($_POST['system'])." ) AND ( ".dateselect($_POST['datelogic'],$_POST['start_date'],$_POST['end_date'])." )";
	$result = db_query($db,$sql);
	if ( PEAR::isError($result) )
	  {
	    echo "<PRE>".$result->getMessage()."</PRE>\n";
	  }
	while ($result->fetchInto($row))
	  {
	    $rkeys=array_keys($row);
	    echo "<TR>";
	    foreach ($rkeys as $rkey)
	      {
		$data[$rkey]=array_shift($row);
                # if a float, format appropriately
		if ( preg_match("/^-?\d*\.\d+$/",$data[$rkey])==1 )
		  {
		    echo "<TD align=\"right\"><PRE>".number_format($data[$rkey],4)."</PRE></TD>";
		  }
		# if an int, format appropriately
		else if ( preg_match("/^-?\d+$/",$data[$rkey])==1 )
		  {
		    echo "<TD align=\"right\"><PRE>".number_format($data[$rkey])."</PRE></TD>";
		  }
                # otherwise print verbatim
		else
		  {
		    echo "<TD align=\"right\"><PRE>".$data[$rkey]."</PRE></TD>";
		  }
	      }
	    echo "</TR>\n";
	    ob_flush();
	    flush();
	  }
      }
    echo "</TABLE>\n";    

    # by institution
    # NOTE By-institution jobstats involves site-specific logic.  You may
    # want to comment out the following statement.
    $inst_summary=true;
    if ( isset($_POST['institution']) && isset($inst_summary) && $inst_summary==true )
      {
	echo "<H3>Usage By Institution</H3>\n";
	if  ( isset($_POST['table']) )
	  {
	    $result=get_metric($db,$_POST['system'],'institution','usage',$_POST['start_date'],$_POST['end_date'],$_POST['datelogic'],false,false);
	    if ( PEAR::isError($result) )
	      {
		echo "<PRE>".$result->getMessage()."</PRE>\n";
	      }
	    metric_as_table($result,'institution','usage');
	  }
	if ( isset($_POST['csv']) )
	  {
	    $result=get_metric($db,$_POST['system'],'institution','usage',$_POST['start_date'],$_POST['end_date'],$_POST['datelogic'],false,false);
	    if ( PEAR::isError($result) )
	      {
		echo "<PRE>".$result->getMessage()."</PRE>\n";
	      }
	    metric_as_csv($result,'institution','usage',$_POST['system'],$_POST['start_date'],$_POST['end_date'],$_POST['datelogic']);
	  }
	if ( isset($_POST['xls']) )
	  {
	    $result=get_metric($db,$_POST['system'],'institution','usage',$_POST['start_date'],$_POST['end_date'],$_POST['datelogic'],false,false);
	    if ( PEAR::isError($result) )
	      {
		echo "<PRE>".$result->getMessage()."</PRE>\n";
	      }
	    metric_as_xls($result,'institution','usage',$_POST['system'],$_POST['start_date'],$_POST['end_date'],$_POST['datelogic']);
	  }
	if ( isset($_POST['ods']) )
	  {
	    $result=get_metric($db,$_POST['system'],'institution','usage',$_POST['start_date'],$_POST['end_date'],$_POST['datelogic'],false,false);
	    if ( PEAR::isError($result) )
	      {
		echo "<PRE>".$result->getMessage()."</PRE>\n";
	      }
	    metric_as_ods($result,'institution','usage',$_POST['system'],$_POST['start_date'],$_POST['end_date']);
	  }
	ob_flush();
	flush();
      }

    # by account
    if ( isset($_POST['account']) )
      {
	echo "<H3>Usage By Account</H3>\n";
	if ( isset($_POST['table']) )
	  {
	    $result=get_metric($db,$_POST['system'],'account','usage',$_POST['start_date'],$_POST['end_date'],$_POST['datelogic'],false,false);
	    if ( PEAR::isError($result) )
	      {
		echo "<PRE>".$result->getMessage()."</PRE>\n";
	      }
	    metric_as_table($result,'account','usage');
	  }
	if ( isset($_POST['csv']) )
	  {
	    $result=get_metric($db,$_POST['system'],'account','usage',$_POST['start_date'],$_POST['end_date'],$_POST['datelogic'],false,false);
	    if ( PEAR::isError($result) )
	      {
		echo "<PRE>".$result->getMessage()."</PRE>\n";
	      }
	    metric_as_csv($result,'account','usage',$_POST['system'],$_POST['start_date'],$_POST['end_date'],$_POST['datelogic']);
	  }
	if ( isset($_POST['xls']) )
	  {
	    $result=get_metric($db,$_POST['system'],'account','usage',$_POST['start_date'],$_POST['end_date'],$_POST['datelogic'],false,false);
	    if ( PEAR::isError($result) )
	      {
		echo "<PRE>".$result->getMessage()."</PRE>\n";
	      }
	    metric_as_xls($result,'account','usage',$_POST['system'],$_POST['start_date'],$_POST['end_date']);
	  }
	if ( isset($_POST['ods']) )
	  {
	    $result=get_metric($db,$_POST['system'],'account','usage',$_POST['start_date'],$_POST['end_date'],$_POST['datelogic'],false,false);
	    if ( PEAR::isError($result) )
	      {
		echo "<PRE>".$result->getMessage()."</PRE>\n";
	      }
	    metric_as_ods($result,'account','usage',$_POST['system'],$_POST['start_date'],$_POST['end_date']);
	  }
	ob_flush();
	flush();
      }

    # software usage
    if ( isset($_POST['software']) )
      {
	echo "<H3>Software Usage</H3>\n";
	$sql = "SELECT sw_app, COUNT(jobid) AS jobs, SUM(".cpuhours($db,$_POST['system'],$_POST['start_date'],$_POST['end_date'],$_POST['datelogic']).") AS cpuhours, SUM(".gpuhours($db,$_POST['system'],$_POST['start_date'],$_POST['end_date'],$_POST['datelogic']).") AS gpuhours, SUM(".nodehours($db,$_POST['system'],$_POST['start_date'],$_POST['end_date'],$_POST['datelogic']).") AS nodehours, SUM(".charges($db,$_POST['system'],$_POST['start_date'],$_POST['end_date'],$_POST['datelogic']).") AS charges, COUNT(DISTINCT(username)) AS users, COUNT(DISTINCT(groupname)) AS groups, COUNT(DISTINCT(account)) AS accounts FROM Jobs WHERE sw_app IS NOT NULL AND ( ".sysselect($_POST['system'])." ) AND ( ".dateselect($_POST['datelogic'],$_POST['start_date'],$_POST['end_date'])." ) GROUP BY sw_app ORDER BY ".$_POST['order']." DESC";
        #echo "<PRE>\n".$sql."</PRE>\n";
	$columns = array("package","jobs","cpuhours","gpuhours","nodehours","charges","users","groups", "accounts");
	if (  isset($_POST['table']) )
	  {
	    $result = db_query($db,$sql);
	    if ( PEAR::isError($result) )
	      {
		echo "<PRE>".$result->getMessage()."</PRE>\n";
	      }
	    result_as_table($result,$columns); 
	  }
	if ( isset($_POST['csv']) )
	  {
	    $result = db_query($db,$sql);
	    if ( PEAR::isError($result) )
	      {
		echo "<PRE>".$result->getMessage()."</PRE>\n";
	      }
	    result_as_csv($result,$columns,$_POST['system']."-software_usage-".$_POST['start_date']."-".$_POST['end_date']);
	  }
	if ( isset($_POST['xls']) )
	  {
	    $result = db_query($db,$sql);
	    if ( PEAR::isError($result) )
	      {
		echo "<PRE>".$result->getMessage()."</PRE>\n";
	      }
	    result_as_xls($result,$columns,$_POST['system']."-software_usage-".$_POST['start_date']."-".$_POST['end_date']);
	  }
	if ( isset($_POST['ods']) )
	  {
	    $result = db_query($db,$sql);
	    if ( PEAR::isError($result) )
	      {
		echo "<PRE>".$result->getMessage()."</PRE>\n";
	      }
	    result_as_ods($result,$columns,$_POST['system']."-software_usage-".$_POST['start_date']."-".$_POST['end_date']);
	  }
      }

    db_disconnect($db);
    page_timer();
    bookmarkable_url();
  }
else
  {
    begin_form("usage-summary.php");

    $year = date('Y',time());
    $month = date('m',time())-1;
    if ( $month<1 )
      {
	$year = $year-1;
	$month = 12;
      }
    $firstday = "01";
    $lastday = "31";
    if ( $month==2 )
      {
	$lastday = 28;
      }
    else if ( $month==4 || $month==6 || $month==9 || $month==11 )
      {
	$lastday = 30;
      }
    
    $start = sprintf("%04d-%02d-%02d",$year,$month,$firstday);
    $end = sprintf("%04d-%02d-%02d",$year,$month,$lastday);

    virtual_system_chooser();
    date_fields($start,$end);

    $orders=array("jobs","cpuhours","gpuhours","nodehours","charges","users","groups");
    checkboxes_from_array("Supplemental reports",array("institution","account","software"));
    $defaultorder="cpuhours";
    pulldown("order","Order results by",$orders,$defaultorder);
    checkbox("Generate HTML tables for supplemental reports","table",1);
    checkbox("Generate CSV files for supplemental reports","csv");
    checkbox("Generate Excel files for supplemental reports","xls");
    checkbox("Generate ODF files for supplemental reports","ods");

    end_form();
  }

page_footer();
?>
