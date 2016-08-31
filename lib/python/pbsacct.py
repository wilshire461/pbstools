#!/usr/bin/python
# This module provides functions for parsing PBS accounting logs for use by 
# various scripts
#
# Copyright 2016 Ohio Supercomputer Center
# Authors:  Aaron Maharry
#           Troy Baer <troy@osc.edu>
#
# License:  GNU GPL v2, see ../COPYING for details.

import gzip
import os
import re
import sys

class jobinfo:
    def __init__(self,jobid,resources):
        self.jobid = jobid
        self.resources = {}
        for key in resources.keys():
            self.resources[key] = resources[key]

    def get_resources(self):
        return self.resources

    def get_resource_keys(self):
        return self.resources.keys()

    def get_resource(self,key):
        if ( key in self.resources.keys() ):
            return self.resources[key]
        else:
            return None

    def set_resource(self,key,value):
        self.resources[key] = value

    def add_to_resource(self,key,value):
        supported_time_resources = ["resources_used.cput","resources_used.walltime"]
        if ( key in supported_time_resources and 
             key not in self.resources.keys() ):
            self.resources[key] = value
        elif ( key in supported_time_resources ):
            oldval = time_to_sec(self.resources[key])
            incr = time_to_sec(value)
            self.resources[key] = sec_to_time(oldval+incr)
        else:
            raise ValueError("Resource \""+key+"\" not supported for addition")

    def numeric_jobid(self):
        """
        Returns the numeric job id (i.e. without the hostname, if any)
        
        Input is of the form: 6072125.oak-batch.osc.edu
        Output is of the form: 6072125
        """
        return int(self.jobid.split(".")[0])

    def nodes_used(self):
        nodes = []
        if ( "exec_host" in self.resources.keys() ):
            for node_and_procs in self.resources["exec_host"].split("+"):
                (node,procs) = node_and_procs.split("/")
                if ( node not in nodes ):
                    nodes.append(node)
        return nodes

    def num_nodes(self):
        nnodes = 0
        if ( "unique_node_count" in self.resources.keys()):
            # Added in TORQUE 4.2.9
            nnodes = int(self.resources["unique_node_count"])
        else:
            nnodes = len(self.nodes_used())
        return nnodes

    def num_processors(self):
        """ Returns the total number of processors the job requires """
        processors = 0
        if ( "total_execution_slots" in self.resources.keys() ):
            # Added in TORQUE 4.2.9
            processors = int(self.resources["total_execution_slots"])
        elif ( "Resource_List.nodes" in self.resources.keys() ):
            # Compute the nodes requested and the processors per node
            for nodes in self.resources["Resource_List.nodes"].split("+"):
                nodes_and_ppn = self.resources["Resource_List.nodes"].split(":")
                try:
                    nodes = int(nodes_and_ppn[0])
                except:
                    # Handles malformed log values
                    nodes = 1
                if ( len(nodes_and_ppn)>=2 ):
                    try:
                        ppn = int(re.search("ppn=(\d+)", nodes_and_ppn[1]).group(1))
                    except AttributeError:
                        ppn = 1
                else:
                    ppn = 1
                nodes = max(1,nodes)
                ppn = max(1,ppn)
                processors = processors + nodes*ppn
            return processors
        ncpus = 0
        if ( "Resource_List.ncpus" in self.resources.keys() ):
            ncpus = max(ncpus,int(self.resources["Resource_List.ncpus"]))
        if ( "resources_used.mppssp" in self.resources.keys() or 
             "resources_used.mppe" in self.resources.keys() ):
            # Cray SV1/X1 specific code
            ssps = 0
            if ( "resources_used.mppssp" in self.resources.keys() ):
                ssps = ssps + int(self.resources["resources_used.mppssp"])
            if ( "resources_used.mppe" in self.resources.keys() ):
                ssps = ssps + 4*int(self.resources["resources_used.mppe"])
            ncpus = max(ncpus,ssps)
        if ( "Resource_List.size" in self.resources.keys() ):
            ncpus = max(ncpus,int(self.resources["Resource_List.size"]))
        # Return the larger of the two computed values
        return max(processors,ncpus)

    def mem_used_kb(self):
        """ Return the amount of memory (in kb) used by the job """
        if ( "resources_used.mem" in self.resources.keys() ):
            return int(re.sub("kb$", "", self.resources["resources_used.mem"]))
        else:
            return 0

    def vmem_used_kb(self):
        """ Return the amount of virtual memory (in kb) used by the job """
        if "resources_used.vmem" in self.resources.keys():
            return int(re.sub("kb$", "", self.resources["resources_used.vmem"]))
        else:
            return 0

    def mem_limit_kb(self):
        if ( "Resource_List.mem" in self.resources.keys() ):
            return mem_to_kb(self.resources["Resource_List.mem"])
        else:
            return 0

    def vmem_limit_kb(self):
        if ( "Resource_List.vmem" in self.resources.keys() ):
            return mem_to_kb(self.resources["Resource_List.vmem"])
        else:
            return 0


def raw_data_from_file(filename):
    """
    Parses a file containing multiple PBS accounting log entries. Returns a list
    of tuples containing the following information:

    (jobid, time, record_type, resources)

    Resources are returned in a dictionary containing entries for each 
    resource name and corresponding value
    """
    try:
        if re.search("\.gz$", filename):
            acct_data = gzip.open(filename)
        else:
            acct_data = open(filename)
    except IOError as e:
        print "ERROR: Failed to read PBS accounting log %s" %filename
        return None
    output = []
    for line in acct_data:
        
        # Get the fields from the log entry
        try:
            time, record_type, jobid, resources = line.split(";")
        except ValueError:
            print("ERROR: Invalid number of fields (requires 4). Unable to \
                    parse entry: %s" %line.split(";"))
            continue
        
        # Create a dict for the various resources
        resources_dict = dict()
        for resource in resources.split(" "):
            match = re.match("^([^=]*)=(.*)", resource)
            if match:
                key = match.group(1)
                value = match.group(2)
                if key in []:
                    value = int(value)
                if key in ["qtime", "start", "end"]:
                    value = float(value)
                resources_dict[key] = value
        
        # Store the data in the output
        output.append((jobid, time, record_type, resources_dict))
        #break
    acct_data.close()
    return output


def data_from_file(filename):
    """
    Parses a file containing multiple PBS accounting log entries.  Returns
    a hash of lightly postprocessed data (i.e. one entry per jobid rather
    than one per record).
    """
    output = {}
    for record in raw_data_from_file(filename):
        jobid = record[0]
        record_type = record[2]
        resources = record[3]
        if ( record_type in ["S","E"] ):
            if ( jobid not in output.keys() ):
                output[jobid] = jobinfo(jobid,resources)
            # may need an extra case here for jobs with multiple S and E
            # records (e.g. preemption)
            else:
                for key in resources.keys():
                    output[jobid].set_resource(key,resources[key])
    return output


def time_to_sec(timestr):
    """
    Convert string time into seconds.
    """
    if ( not re.match("[\d:]+",timestr) ):
        raise ValueError("Malformed time \""+timestr+"\"")
    sec = 0
    elt = timestr.split(":")
    if ( len(elt)==1 ):
        # raw seconds -- TORQUE 5.1.2 did this on walltime and cput 
        # for some reason
        sec = int(elt[0])
    elif ( len(elt)==2 ):
        # mm:ss -- should be rare to nonexistent in TORQUE
        sec = 60*int(elt[0])+int(elt[1])
    elif ( len(elt)==3 ):
        # hh:mm:ss -- most common case
        sec = 3600*int(elt[0])+60*int(elt[1])+int(elt[2])
    elif ( len(elt)==4 ):
        # dd:hh:mm:ss -- not used in TORQUE, occasionally appears in Moab
        # output
        sec = 3600*(24*int(elt[0])+int(elt[1]))+60*int(elt[2])+int(elt[2])
    else:
        raise ValueError("Malformed time \""+timestr+"\"")
    return sec


def sec_to_time(seconds):
    hours = seconds/3600
    minutes = (seconds-3600*hours)/60
    sec = seconds-(3600*hours+60*minutes)
    return "%d:%02d:%02d" % (hours,minutes,sec)


def mem_to_kb(memstr):
    match = re.match("^(\d+)([TtGgMmKk])([BbWw])$",memstr)
    if ( match is not None and len(match.groups())==3 ):
        number = int(match.group(1))
        multiplier = 1
        numbytes = 1
        factor = match.group(2)
        if ( factor in ["T","t"] ):
            multiplier = 1024*1024*1024
        elif ( factor in ["G","g"] ):
            multiplier = 1024*1024
        elif ( factor in ["M","m"] ):
            multiplier = 1024
        units = match.group(3)
        if ( units in ["W","w"] ):
            numbytes = 8
        return number*multiplier*numbytes    
    else:
        raise ValueError("Invalid memory expression \""+memstr+"\"")


if __name__ == "__main__":
    import os
    print str(data_from_file(os.path.expanduser("~amaharry/acct-data/20160310")))
