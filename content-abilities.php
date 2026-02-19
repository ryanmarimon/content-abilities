<?php
/**
 * Plugin Name: Content Abilities
 * Description: Adds content management abilities (posts, pages, blocks) to the Abilities API for MCP integration.
 * Version: 1.0.0
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Author: Ryan
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Bail if the Abilities API is not available.
if ( ! function_exists( 'wp_register_ability' ) ) {
	return;
}

/**
 * Fetches a post by ID and validates it exists and is a post or page.
 *
 * @param int $id Post ID.
 * @return WP_Post|WP_Error
 */
function content_abilities_get_post_or_error( int $id ) {
	$post = get_post( $id );
	if ( ! $post ) {
		return new WP_Error( 'not_found', 'Post not found.' );
	}
	if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
		return new WP_Error( 'invalid_type', 'Only posts and pages are supported.' );
	}
	return $post;
}

/**
 * Formats a WP_Post into a standard output array.
 *
 * @param WP_Post $post Post object.
 * @return array
 */
function content_abilities_format_post( WP_Post $post ): array {
	$result = array(
		'id'        => $post->ID,
		'title'     => get_the_title( $post ),
		'content'   => $post->post_content,
		'excerpt'   => $post->post_excerpt,
		'status'    => $post->post_status,
		'type'      => $post->post_type,
		'author'    => (int) $post->post_author,
		'date'      => $post->post_date,
		'modified'  => $post->post_modified,
		'permalink' => get_permalink( $post ),
	);

	if ( 'post' === $post->post_type ) {
		$categories = wp_get_post_categories( $post->ID, array( 'fields' => 'all' ) );
		$tags       = wp_get_post_tags( $post->ID );
		$result['categories'] = array_map(
			function ( $cat ) {
				return array( 'id' => $cat->term_id, 'name' => $cat->name, 'slug' => $cat->slug );
			},
			is_array( $categories ) ? $categories : array()
		);
		$result['tags'] = array_map(
			function ( $tag ) {
				return array( 'id' => $tag->term_id, 'name' => $tag->name, 'slug' => $tag->slug );
			},
			is_array( $tags ) ? $tags : array()
		);
	}

	return $result;
}

/**
 * Filters out blocks with null blockName (filler blocks).
 *
 * @param array $blocks Parsed blocks.
 * @return array Filtered blocks.
 */
function content_abilities_filter_blocks( array $blocks ): array {
	return array_values(
		array_filter(
			$blocks,
			function ( $block ) {
				return null !== $block['blockName'];
			}
		)
	);
}

/**
 * Cleans a parsed block for output: strips innerContent, adds index, recurses innerBlocks.
 *
 * @param array $block Parsed block.
 * @param int   $index Block index.
 * @return array Cleaned block.
 */
function content_abilities_clean_block( array $block, int $index ): array {
	$inner_blocks = content_abilities_filter_blocks( isset( $block['innerBlocks'] ) ? $block['innerBlocks'] : array() );
	$cleaned_inner = array();
	foreach ( $inner_blocks as $i => $inner ) {
		$cleaned_inner[] = content_abilities_clean_block( $inner, $i );
	}

	return array(
		'index'       => $index,
		'blockName'   => $block['blockName'],
		'attrs'       => ! empty( $block['attrs'] ) ? $block['attrs'] : new \stdClass(),
		'innerHTML'   => isset( $block['innerHTML'] ) ? $block['innerHTML'] : '',
		'innerBlocks' => $cleaned_inner,
	);
}

// Register category and abilities.
add_action( 'wp_abilities_api_categories_init', 'content_abilities_register_categories' );
add_action( 'wp_abilities_api_init', 'content_abilities_register_abilities' );

/**
 * Registers the content ability category.
 */
function content_abilities_register_categories(): void {
	wp_register_ability_category(
		'content',
		array(
			'label'       => __( 'Content' ),
			'description' => __( 'Abilities for managing posts, pages, and block content.' ),
		)
	);
}

/**
 * Registers all content abilities.
 */
function content_abilities_register_abilities(): void {
	$category = 'content';

	// -------------------------------------------------------------------------
	// content/list-posts
	// -------------------------------------------------------------------------
	wp_register_ability(
		'content/list-posts',
		array(
			'label'               => __( 'List Posts' ),
			'description'         => __( 'Search and filter posts and pages with pagination. Supports filtering by type, status, category, tag, search term, and ordering.' ),
			'category'            => $category,
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'type'     => array(
						'type'        => 'string',
						'enum'        => array( 'post', 'page' ),
						'description' => __( 'Post type to filter by. Defaults to post.' ),
					),
					'status'   => array(
						'type'        => 'string',
						'enum'        => array( 'publish', 'draft', 'pending', 'private', 'trash', 'any' ),
						'description' => __( 'Post status to filter by. Defaults to any.' ),
					),
					'category' => array(
						'type'        => 'string',
						'description' => __( 'Category slug to filter by (posts only).' ),
					),
					'tag'      => array(
						'type'        => 'string',
						'description' => __( 'Tag slug to filter by (posts only).' ),
					),
					'search'   => array(
						'type'        => 'string',
						'description' => __( 'Search term to filter posts by.' ),
					),
					'orderby'  => array(
						'type'        => 'string',
						'enum'        => array( 'date', 'title', 'modified', 'ID' ),
						'description' => __( 'Field to order results by. Defaults to date.' ),
					),
					'order'    => array(
						'type'        => 'string',
						'enum'        => array( 'ASC', 'DESC' ),
						'description' => __( 'Sort direction. Defaults to DESC.' ),
					),
					'page'     => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Page number for pagination. Defaults to 1.' ),
					),
					'per_page' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 100,
						'description' => __( 'Number of posts per page. Defaults to 20.' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => static function ( $input = array() ) {
				$input = is_array( $input ) ? $input : array();
				$args  = array(
					'post_type'      => isset( $input['type'] ) ? $input['type'] : 'post',
					'post_status'    => isset( $input['status'] ) ? $input['status'] : 'any',
					'orderby'        => isset( $input['orderby'] ) ? $input['orderby'] : 'date',
					'order'          => isset( $input['order'] ) ? $input['order'] : 'DESC',
					'paged'          => isset( $input['page'] ) ? $input['page'] : 1,
					'posts_per_page' => isset( $input['per_page'] ) ? $input['per_page'] : 20,
				);

				if ( ! empty( $input['search'] ) ) {
					$args['s'] = $input['search'];
				}
				if ( ! empty( $input['category'] ) ) {
					$args['category_name'] = $input['category'];
				}
				if ( ! empty( $input['tag'] ) ) {
					$args['tag'] = $input['tag'];
				}

				$query = new WP_Query( $args );
				$posts = array();
				foreach ( $query->posts as $post ) {
					$posts[] = array(
						'id'       => $post->ID,
						'title'    => get_the_title( $post ),
						'status'   => $post->post_status,
						'type'     => $post->post_type,
						'date'     => $post->post_date,
						'modified' => $post->post_modified,
						'author'   => (int) $post->post_author,
					);
				}

				return array(
					'posts'       => $posts,
					'total'       => (int) $query->found_posts,
					'total_pages' => (int) $query->max_num_pages,
					'page'        => $args['paged'],
				);
			},
			'permission_callback' => static function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
				'mcp'          => array(
					'public' => true,
				),
			),
		)
	);

	// -------------------------------------------------------------------------
	// content/get-post
	// -------------------------------------------------------------------------
	wp_register_ability(
		'content/get-post',
		array(
			'label'               => __( 'Get Post' ),
			'description'         => __( 'Get full post details including title, content, excerpt, status, author, categories, tags, and permalink.' ),
			'category'            => $category,
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'description' => __( 'The post ID.' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => static function ( $input ) {
				$post = content_abilities_get_post_or_error( (int) $input['id'] );
				if ( is_wp_error( $post ) ) {
					return $post;
				}
				return content_abilities_format_post( $post );
			},
			'permission_callback' => static function ( $input ): bool {
				return current_user_can( 'edit_post', (int) $input['id'] );
			},
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
				'mcp'          => array(
					'public' => true,
				),
			),
		)
	);

	// -------------------------------------------------------------------------
	// content/get-post-blocks
	// -------------------------------------------------------------------------
	wp_register_ability(
		'content/get-post-blocks',
		array(
			'label'               => __( 'Get Post Blocks' ),
			'description'         => __( 'Parse post content into structured block JSON. Returns each block with index, blockName, attrs, innerHTML, and innerBlocks.' ),
			'category'            => $category,
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'description' => __( 'The post ID.' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => static function ( $input ) {
				$post = content_abilities_get_post_or_error( (int) $input['id'] );
				if ( is_wp_error( $post ) ) {
					return $post;
				}

				$parsed  = parse_blocks( $post->post_content );
				$blocks  = content_abilities_filter_blocks( $parsed );
				$cleaned = array();
				foreach ( $blocks as $i => $block ) {
					$cleaned[] = content_abilities_clean_block( $block, $i );
				}

				return array(
					'post_id' => $post->ID,
					'blocks'  => $cleaned,
				);
			},
			'permission_callback' => static function ( $input ): bool {
				return current_user_can( 'edit_post', (int) $input['id'] );
			},
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
				'mcp'          => array(
					'public' => true,
				),
			),
		)
	);

	// -------------------------------------------------------------------------
	// content/list-categories
	// -------------------------------------------------------------------------
	wp_register_ability(
		'content/list-categories',
		array(
			'label'               => __( 'List Categories' ),
			'description'         => __( 'List all categories with id, name, slug, count, and parent.' ),
			'category'            => $category,
			'execute_callback'    => static function (): array {
				$terms = get_terms(
					array(
						'taxonomy'   => 'category',
						'hide_empty' => false,
					)
				);
				if ( is_wp_error( $terms ) ) {
					return array();
				}
				$result = array();
				foreach ( $terms as $term ) {
					$result[] = array(
						'id'     => $term->term_id,
						'name'   => $term->name,
						'slug'   => $term->slug,
						'count'  => $term->count,
						'parent' => $term->parent,
					);
				}
				return $result;
			},
			'permission_callback' => static function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
				'mcp'          => array(
					'public' => true,
				),
			),
		)
	);

	// -------------------------------------------------------------------------
	// content/list-tags
	// -------------------------------------------------------------------------
	wp_register_ability(
		'content/list-tags',
		array(
			'label'               => __( 'List Tags' ),
			'description'         => __( 'List all tags with id, name, slug, and count.' ),
			'category'            => $category,
			'execute_callback'    => static function (): array {
				$terms = get_terms(
					array(
						'taxonomy'   => 'post_tag',
						'hide_empty' => false,
					)
				);
				if ( is_wp_error( $terms ) ) {
					return array();
				}
				$result = array();
				foreach ( $terms as $term ) {
					$result[] = array(
						'id'    => $term->term_id,
						'name'  => $term->name,
						'slug'  => $term->slug,
						'count' => $term->count,
					);
				}
				return $result;
			},
			'permission_callback' => static function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
				'mcp'          => array(
					'public' => true,
				),
			),
		)
	);

	// -------------------------------------------------------------------------
	// content/create-post
	// -------------------------------------------------------------------------
	wp_register_ability(
		'content/create-post',
		array(
			'label'               => __( 'Create Post' ),
			'description'         => __( 'Create a new post or page with optional block content, categories, tags, and status.' ),
			'category'            => $category,
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'title' ),
				'properties'           => array(
					'title'      => array(
						'type'        => 'string',
						'description' => __( 'The post title.' ),
					),
					'content'    => array(
						'type'        => 'string',
						'description' => __( 'The post content. Supports block markup.' ),
					),
					'excerpt'    => array(
						'type'        => 'string',
						'description' => __( 'The post excerpt.' ),
					),
					'status'     => array(
						'type'        => 'string',
						'enum'        => array( 'publish', 'draft', 'pending', 'private' ),
						'description' => __( 'The post status. Defaults to draft.' ),
					),
					'type'       => array(
						'type'        => 'string',
						'enum'        => array( 'post', 'page' ),
						'description' => __( 'The post type. Defaults to post.' ),
					),
					'categories' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'integer' ),
						'description' => __( 'Array of category IDs (posts only).' ),
					),
					'tags'       => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Array of tag names (posts only). Tags are created if they do not exist.' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => static function ( $input ) {
				$post_type = isset( $input['type'] ) ? $input['type'] : 'post';
				$status    = isset( $input['status'] ) ? $input['status'] : 'draft';

				$post_data = array(
					'post_title'   => sanitize_text_field( $input['title'] ),
					'post_content' => isset( $input['content'] ) ? $input['content'] : '',
					'post_excerpt' => isset( $input['excerpt'] ) ? sanitize_textarea_field( $input['excerpt'] ) : '',
					'post_status'  => $status,
					'post_type'    => $post_type,
				);

				$post_id = wp_insert_post( $post_data, true );
				if ( is_wp_error( $post_id ) ) {
					return $post_id;
				}

				if ( 'post' === $post_type ) {
					if ( ! empty( $input['categories'] ) ) {
						wp_set_post_categories( $post_id, $input['categories'] );
					}
					if ( ! empty( $input['tags'] ) ) {
						wp_set_post_tags( $post_id, $input['tags'] );
					}
				}

				$post = get_post( $post_id );
				return content_abilities_format_post( $post );
			},
			'permission_callback' => static function ( $input ): bool {
				if ( ! current_user_can( 'edit_posts' ) ) {
					return false;
				}
				$status = isset( $input['status'] ) ? $input['status'] : 'draft';
				if ( 'publish' === $status && ! current_user_can( 'publish_posts' ) ) {
					return false;
				}
				return true;
			},
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
				'mcp'          => array(
					'public' => true,
				),
			),
		)
	);

	// -------------------------------------------------------------------------
	// content/update-post
	// -------------------------------------------------------------------------
	wp_register_ability(
		'content/update-post',
		array(
			'label'               => __( 'Update Post' ),
			'description'         => __( 'Update any fields on an existing post or page: title, content, excerpt, status, categories, or tags.' ),
			'category'            => $category,
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'         => array(
						'type'        => 'integer',
						'description' => __( 'The post ID to update.' ),
					),
					'title'      => array(
						'type'        => 'string',
						'description' => __( 'New post title.' ),
					),
					'content'    => array(
						'type'        => 'string',
						'description' => __( 'New post content. Supports block markup.' ),
					),
					'excerpt'    => array(
						'type'        => 'string',
						'description' => __( 'New post excerpt.' ),
					),
					'status'     => array(
						'type'        => 'string',
						'enum'        => array( 'publish', 'draft', 'pending', 'private', 'trash' ),
						'description' => __( 'New post status.' ),
					),
					'categories' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'integer' ),
						'description' => __( 'Array of category IDs to set (posts only). Replaces existing categories.' ),
					),
					'tags'       => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Array of tag names to set (posts only). Replaces existing tags. Tags are created if they do not exist.' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => static function ( $input ) {
				$post = content_abilities_get_post_or_error( (int) $input['id'] );
				if ( is_wp_error( $post ) ) {
					return $post;
				}

				$post_data = array( 'ID' => $post->ID );

				if ( isset( $input['title'] ) ) {
					$post_data['post_title'] = sanitize_text_field( $input['title'] );
				}
				if ( isset( $input['content'] ) ) {
					$post_data['post_content'] = $input['content'];
				}
				if ( isset( $input['excerpt'] ) ) {
					$post_data['post_excerpt'] = sanitize_textarea_field( $input['excerpt'] );
				}
				if ( isset( $input['status'] ) ) {
					$post_data['post_status'] = $input['status'];
				}

				$result = wp_update_post( $post_data, true );
				if ( is_wp_error( $result ) ) {
					return $result;
				}

				if ( 'post' === $post->post_type ) {
					if ( isset( $input['categories'] ) ) {
						wp_set_post_categories( $post->ID, $input['categories'] );
					}
					if ( isset( $input['tags'] ) ) {
						wp_set_post_tags( $post->ID, $input['tags'] );
					}
				}

				$updated_post = get_post( $post->ID );
				return content_abilities_format_post( $updated_post );
			},
			'permission_callback' => static function ( $input ): bool {
				return current_user_can( 'edit_post', (int) $input['id'] );
			},
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
				'mcp'          => array(
					'public' => true,
				),
			),
		)
	);

	// -------------------------------------------------------------------------
	// content/delete-post
	// -------------------------------------------------------------------------
	wp_register_ability(
		'content/delete-post',
		array(
			'label'               => __( 'Delete Post' ),
			'description'         => __( 'Move a post or page to the trash, or permanently delete it.' ),
			'category'            => $category,
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'    => array(
						'type'        => 'integer',
						'description' => __( 'The post ID to delete.' ),
					),
					'force' => array(
						'type'        => 'boolean',
						'description' => __( 'If true, permanently deletes the post instead of trashing it. Defaults to false.' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => static function ( $input ) {
				$post = content_abilities_get_post_or_error( (int) $input['id'] );
				if ( is_wp_error( $post ) ) {
					return $post;
				}

				$force  = ! empty( $input['force'] );
				$result = wp_delete_post( $post->ID, $force );

				if ( ! $result ) {
					return new WP_Error( 'delete_failed', 'Failed to delete the post.' );
				}

				return array(
					'id'      => $post->ID,
					'deleted' => true,
					'trashed' => ! $force,
				);
			},
			'permission_callback' => static function ( $input ): bool {
				return current_user_can( 'delete_post', (int) $input['id'] );
			},
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
				'mcp'          => array(
					'public' => true,
				),
			),
		)
	);

	// -------------------------------------------------------------------------
	// content/update-post-blocks
	// -------------------------------------------------------------------------
	wp_register_ability(
		'content/update-post-blocks',
		array(
			'label'               => __( 'Update Post Blocks' ),
			'description'         => __( 'Perform block-level operations on a post: replace_all (replace entire content), insert (add block at index), remove (delete block at index), or replace (swap block at index). Use get-post-blocks first to see current layout and indices.' ),
			'category'            => $category,
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'operation' ),
				'properties'           => array(
					'id'        => array(
						'type'        => 'integer',
						'description' => __( 'The post ID.' ),
					),
					'operation' => array(
						'type'        => 'string',
						'enum'        => array( 'replace_all', 'insert', 'remove', 'replace' ),
						'description' => __( 'The block operation to perform.' ),
					),
					'content'   => array(
						'type'        => 'string',
						'description' => __( 'Block markup content. Required for replace_all, insert, and replace operations.' ),
					),
					'index'     => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'Block index to operate on. Required for insert, remove, and replace operations.' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => static function ( $input ) {
				$post = content_abilities_get_post_or_error( (int) $input['id'] );
				if ( is_wp_error( $post ) ) {
					return $post;
				}

				$operation = $input['operation'];

				if ( 'replace_all' === $operation ) {
					if ( ! isset( $input['content'] ) ) {
						return new WP_Error( 'missing_content', 'The content field is required for replace_all.' );
					}
					$new_content = $input['content'];
				} else {
					if ( ! isset( $input['index'] ) ) {
						return new WP_Error( 'missing_index', 'The index field is required for insert, remove, and replace operations.' );
					}

					$parsed = parse_blocks( $post->post_content );
					$blocks = content_abilities_filter_blocks( $parsed );
					$index  = (int) $input['index'];

					if ( 'insert' === $operation ) {
						if ( ! isset( $input['content'] ) ) {
							return new WP_Error( 'missing_content', 'The content field is required for insert.' );
						}
						$new_blocks = parse_blocks( $input['content'] );
						$new_blocks = content_abilities_filter_blocks( $new_blocks );
						if ( $index > count( $blocks ) ) {
							$index = count( $blocks );
						}
						array_splice( $blocks, $index, 0, $new_blocks );
					} elseif ( 'remove' === $operation ) {
						if ( $index >= count( $blocks ) ) {
							return new WP_Error( 'invalid_index', 'Block index out of range.' );
						}
						array_splice( $blocks, $index, 1 );
					} elseif ( 'replace' === $operation ) {
						if ( ! isset( $input['content'] ) ) {
							return new WP_Error( 'missing_content', 'The content field is required for replace.' );
						}
						if ( $index >= count( $blocks ) ) {
							return new WP_Error( 'invalid_index', 'Block index out of range.' );
						}
						$new_blocks = parse_blocks( $input['content'] );
						$new_blocks = content_abilities_filter_blocks( $new_blocks );
						array_splice( $blocks, $index, 1, $new_blocks );
					}

					$new_content = serialize_blocks( $blocks );
				}

				$result = wp_update_post(
					array(
						'ID'           => $post->ID,
						'post_content' => $new_content,
					),
					true
				);

				if ( is_wp_error( $result ) ) {
					return $result;
				}

				// Return updated blocks.
				$updated_post = get_post( $post->ID );
				$parsed       = parse_blocks( $updated_post->post_content );
				$blocks       = content_abilities_filter_blocks( $parsed );
				$cleaned      = array();
				foreach ( $blocks as $i => $block ) {
					$cleaned[] = content_abilities_clean_block( $block, $i );
				}

				return array(
					'post_id' => $post->ID,
					'blocks'  => $cleaned,
				);
			},
			'permission_callback' => static function ( $input ): bool {
				return current_user_can( 'edit_post', (int) $input['id'] );
			},
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
				'mcp'          => array(
					'public' => true,
				),
			),
		)
	);
}
