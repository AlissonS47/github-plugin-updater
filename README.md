# GithubPluginUpdater

Class to check and update WordPress plugins hosted on GitHub.

## Table of Contents

- [What it is](#what-it-is)
- [How it works](#how-it-works)
- [How to use it](#how-to-use-it)
  - [Requirements](#requirements)
  - [Installation](#installation)
- [Notes](#notes)
- [License and Final Message](#license-and-final-message)

---

## What It Is

`GithubPluginUpdater` is a PHP class developed to facilitate updating WordPress plugins hosted in GitHub repositories. Its main purpose is to check the latest plugin version according to the latest release on GitHub and compare it with the version installed in WordPress, providing an update option in the plugins panel, just as if it were hosted on wordpress.org.

This class is useful for developers who manage plugins on GitHub and want to provide a practical way for their users or clients to keep plugins up to date without manually downloading new versions.

---

## How It Works

The `GithubPluginUpdater` works in the background to check for plugin updates. It integrates with WordPress's native update system, using the following steps to perform its tasks:

1. **Version Check**: The class makes a request to the GitHub API, retrieving information about the latest available version in the repository according to the latest release.
   
2. **Comparison**: If the version installed in WordPress is older than the version available on GitHub, the plugin indicates that an update is available.

3. **Update**: When the administrator chooses to update the plugin, the class collects all the necessary data and sends it to WordPress, allowing the native plugin update system to take care of the rest.

The class uses an `info.json` file that must be attached to the release assets to obtain information about the plugin, see [Info.json Template](#infojson-template). It also supports private repositories by using GitHub Personal Access Tokens to make requests.

---

## How to use it

### Requirements

To ensure that `GithubPluginUpdater` works correctly, the following requirements must be met:

- **Releases**: The class checks the latest release in the repository through the GitHub API, meaning the class will not work without a release.
- **Info.json**: The GitHub repository must contain an `info.json` file attached as an asset in the release, containing the plugin details, see [Info.json Template](#infojson-template).
- **GitHub Token(optional)**: A Personal Access Token may be required if the repository is private.

### Info.json Template
```json
{
    "name" : "Plugin Name",
    "slug" : "plugin-name",
    "author" : "<a href='https://example.com/'>Author</a>",
    "version" : "1.0.6",
    "requires" : "3.0",
    "tested" : "5.8",
    "requires_php" : "5.3",
    "sections" : {
        "description" : "Plugin description",
        "installation" : "Plugin installation guide",
        "changelog" : "<h4>1.0.6 –  29 october 2024</h4><ul><li>Bug fixes.</li></ul>"
    }
}
```

### Installation

1. **Insert the class**
   
   This is simple: just copy the class and place it where it fits best in your plugin project. The important thing is that the class is imported and instantiated in the main file of your plugin.
   ```php
   require_once 'path/to/class.github_plugin_updater.php';

2. **Instantiate the class**
   
   Instantiate the class in the main file of your plugin, sending the necessary parameters:
   ```php
   $updater = new GithubPluginUpdater(
      __FILE__, // Main plugin file
      'your-username', // Your GitHub username
      'repository-name', // Repository name
      'your-github-token' // (Optional) Access token for private repositories
   );
   ```
   Done! Your plugin will now automatically check for updates.

## Notes

- All fields in the info.json are required, if any are missing, the update/check process will not be completed. Some fields contain HTML tags, which are not necessary but demonstrate that their use is possible.
- In the info.json, "requires" and "tested" refer to the minimum WordPress version required and the version tested, while the "sections" fields are used when the user checks the update details.
- The plugin slug and plugin folder must have the same name, this is crucial when WordPress replaces the files. When installing a plugin where the zip/folder name includes more than just the slug, rename it to the slug only. For example, it’s common to download a release where both the zip file and main folder appear as "plugin-name-v1.0.1"; in these cases, rename it to just the plugin slug, "plugin-name". This must be done because if the installed plugin and update have different names, WordPress will not replace the folders correctly and will likely create a new folder. During the update, the `GithubPluginUpdater` class removes any information beyond the slug in the update folder name, but for it to work properly, the previously installed plugin must follow the same rule.
- The GitHub API imposes a request limit to control usage. Unauthenticated users have a lower hourly limit from the same IP address, while authenticated users (using a Personal Access Token) have a significantly higher limit, which is useful for private repositories or scenarios where multiple update checks may occur. The `GithubPluginUpdater` uses 24 hour cache to avoid unnecessary requests. Still, to ensure checks work consistently, especially in plugins that may be installed on multiple sites, provide a Personal Access Token during setup. Besides preventing rate limits from being exceeded, it allows access to private repositories.

## License and Final Message

This code was created to solve a problem I faced myself when updating plugins from GitHub, so I thought it was worth sharing to make life easier for other devs as well. The GithubPluginUpdater is available under the MIT license, meaning you can use, modify, and adapt it as you wish!

I hope it's useful and helps simplify the plugin update process. Feel free to customize and use it however you need, enjoy!

   
