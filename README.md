# YouTube Bulk Importer

This plugin lets you search YouTube from inside your WordPress dashboard and turn the videos you select into posts in a few clicks. Each new post shows the video as a playable embed, and can include the YouTube description, tags, a category, and the video thumbnail as its featured image. It is built for site owners who publish video content and want to fill their site with YouTube videos without copying each one by hand. A YouTube Data API key from a Google account is required.

## Importing Videos

- Select videos one by one, by clicking their thumbnails, or all at once, then import them in a single batch.
- Choose a category and a post status, such as draft, published, pending review, or private, for each batch.
- Edit the title, description, and tags of any video before importing it.
- Open an edit window that plays the video, so you can check it before you import it.
- A counter shows how many videos in the batch are done.
- Each video shows its own progress, from queued to importing to imported.
- Videos that fail to import show the reason and a button to try again.
- The same video is never imported twice, even when two people import it at the same moment.
- Hide videos you have already imported to keep the list fresh on new ones.
- Clear all results to start a new search.
- Switch the results between a grid and a list layout, and your choice is remembered.
- You are warned before leaving the page while an import is still running.

## Finding Videos

- Search YouTube by keyword from a dedicated import screen in your dashboard.
- Pull in the videos of a single channel by entering its @handle, channel link, or channel ID.
- Pull in every video from a playlist by pasting the playlist link or its ID.
- Sort keyword and channel results by relevance, newest, most viewed, top rated, or title.
- Limit keyword and channel results to videos published in the last hour, day, week, month, or year.
- Load more videos below the current results with a single load more button.
- See each video's thumbnail, length, view count, and publish date before you import it.
- Videos you have already imported are marked as imported, with a link to edit their post.
- Recent searches are remembered for 15 minutes, so repeating a search or loading more does not use up your YouTube api credits.
- Clear error messages explain what to fix when a channel or playlist is not found, the API key is rejected, the key is restricted to certain websites or addresses, YouTube access is not turned on in your Google account, or the daily limit is used up.

## Settings

- Store your YouTube API key in a hidden field that only shows its last four characters once saved.
- Test your API key with one click before saving it, or test the key already saved.
- A test that finds the daily YouTube limit used up still confirms the key works, with a notice about the limit.
- Set how many videos appear per page of results, from 1 to 50.
- Pick the default status for imported posts.
- Import videos into regular posts or any other public content type on your site.
- Choose which category list and which tag list imported videos are filed under.
- Turn the description, tags, featured image, and video length options on or off.
- Choose a high quality featured image or a smaller standard size for faster imports.
- Save posts in the format of the block editor, the classic editor, or whichever editor the content type uses.

## What Each Imported Post Contains

- The YouTube video appears at the top of the post as a playable, widescreen embed.
- The YouTube description can be added below the video, with its paragraphs kept and its web addresses turned into clickable links.
- The video thumbnail can be downloaded and set as the post's featured image.
- YouTube tags can be added to the post, with duplicates removed and an optional limit on how many are kept.
- The post is marked with the video post format when your theme supports post formats.
- The user who ran the import is set as the post's author.
- The video length can be saved with the post for use by other tools or custom templates.
- If the featured image cannot be downloaded, the post is still created and a notice explains what went wrong.

## Admin Tools and Access

- A YouTube Importer menu in the dashboard has the Import Videos, Imported Videos, and Settings screens.
- The Imported Videos screen lists only posts that came from YouTube, with a link to each original video.
- Only site admins can search, import, or change settings.
- A notice on the import screen links to the settings page when no API key has been added.
- Uninstalling the plugin removes its settings but keeps every imported post, image, category, and tags.

## Title Cleanup

- Remove emoji from video titles while keeping symbols such as trademark and copyright signs.
- Remove hashtags from the end of titles, and drop just the # sign from hashtags in the middle of a title.
- Remove @mentions from the end of titles, including words such as "by" or "ft." before them.
- Titles keep numbers such as #1, and a title is never left empty by the cleanup.
