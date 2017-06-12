Fetch Remote Url
===

Fetch a remote URL using `wpcom_vip_file_get_contents` 

## Installation ##

1. Upload `fetch-remote-url` to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.

## Usage ##

Example usage:  Returning youtube `playlistItems` API in json array `$Fetch_Remote_Url->fru_get_json( $url )`
```php
<?php 
// $Fetch_Remote_Url->fru_get_json( $url );
$videos = $Fetch_Remote_Url->fru_get_json( 'https://www.googleapis.com/youtube/v3/playlistItems?part=snippet&contentDetails&playlistId={$youtuePlaylistID}&maxResults=5&order=date&key={$youtueAPIkey}' );
?>

<div class="dummy__block">
	<?php if ( $videos ) :
    foreach ( $videos["items"] as $video ) : 
      //var_dump( $video["snippet"]["resourceId"]["videoId"] ); ?>
      <a href="<?php echo esc_url( 'https://www.youtube.com/watch?v=' . $video["snippet"]["resourceId"]["videoId"] ); ?>" class="dummy__block-url">
        <img src="<?php echo esc_url( $video["snippet"]["thumbnails"]["medium"]["url"] ); ?>" />
      </a>
    <?php endforeach;
  endif; ?>
</div>
```