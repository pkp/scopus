# OJS Scopus Export Plugin

This plugin for OJS 3 exports and delivers an article's uploaded JATS full-text XML and primary PDF
galley to Scopus, for its Version of Record.

Both files are delivered as uploaded/generated, unmodified, as two separate files (not packaged
together) — via SFTP, either on demand (Register/Export) or automatically once a day for journals
that opt in.

## Installation

Place this plugin's contents at `plugins/generic/scopus` in your OJS installation, then enable it
under Settings > Website > Plugins > Generic Plugins.

The export functionality can then be accessed through:
- Tools > Import/Export > Scopus Export Plugin

## Configuration

Under the plugin's settings, journals may configure an SFTP account (hostname, username, password,
and optionally a port and path) to deliver content directly to Scopus. The account is optional — a
journal can use Export to download packages and deliver them manually instead — but if any of
hostname/username/password is set, all three are required.

An "automatic registration" option delivers any undelivered Version of Record content once a day,
without needing to click Register manually. It requires a fully configured SFTP account.

## Contact/Support

Support requests can be made at our [Community Forum](https://forum.pkp.sfu.ca/).
Learn more about how to [report a problem](https://docs.pkp.sfu.ca/dev/contributors/#report-a-problem).

## License

This plugin is licensed under the GNU General Public License. See the file LICENSE for the complete terms of this license.
