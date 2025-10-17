To use this plugin create a folder wp-content/plugins/bp-em-advanced-group-search and then place the file bp-em-advanced-group-search.php in that folder and then activate the plugin.
How to Use (you can use group ids or group slugs)
You can now use the group attribute in your Events Manager shortcodes or function calls like this:
Show events from groups 1, 5, and 12: [events_list group="1,5,12"]
Show events from any group except group 7: [events_list group="-7"]
Show events from any group except groups 7, 8, and 9: [events_list group="-7,-8,-9"]
