<?php


class DevShopGitHubApi {

  /**
   * @var \Github\Client
   */
  public $client;

  /**
   * @var stdClass
   */
  public $environment;

  public function __construct()
  {
    try {
      $this->client = devshop_github_client();
    }
    catch (\Exception $e) {
      throw $e;
    }
  }

  /**
   * Create a GitHub "Deployment" and "Deployment Status".
   *
   * @param $environment
   *   An devshop environment object.
   *
   * @param $state
   *   The state of the deployment status. Options are:
   *   error, failure, pending, in_progress, queued, or success
   *
   * @param string $sha
   *   A specific git commit SHA, if desired. If left empty, deployment will be
   *   made against the environment "git_ref", a branch or tag.
   * @param $log_url
   */
  static function createDeployment($environment, $state = 'pending', $new = true, $description = NULL, $sha  = NULL, $log_url = NULL) {

    $project = $environment->project;
    $hostmaster_uri = hosting_get_hostmaster_uri();

    // If Deployment doesn't already exist, create it.
    try {
    $client = devshop_github_client();

    if ($new || empty($environment->github_deployments)) {
      $deployment = new stdClass();

      // Git Reference. Use sha if specified.
      // @TODO: Detect the actual SHA from git or the PR here?
      // NO PR SPECIFIC THINGS in Deployments.
      $deployment->ref = $sha? $sha: $environment->git_ref;

      // In GitHub's API, "environment" is just a small string it displays on the pull request:
      // It's a better  UX to show the full URI in the "environment" field.
      $deployment->environment = $environment->uri;
      $deployment->payload = array(
        'devshop_site_url' => $environment->dashboard_url,
        'devmaster_url' => $hostmaster_uri,
      );
      $deployment->description = t('Deploying git reference %ref to environment %env for project %proj: %link [by %server]', array(
        '%ref' => $deployment->ref,
        '%env' => $environment->name,
        '%project' => $project->name,
        '%link' => $deployment->environment,
        '%server' => $hostmaster_uri,
      ));
      $deployment->required_contexts = array();

      // @TODO: Use the developer preview to get this flag: https://developer.github.com/v3/previews/#enhanced-deployments
      $deployment->transient_environment = true;

      // @TODO: Support deployment notifications for production.
      $deployment->production_environment = false;

      // Create Deployment
      $post_url = "/repos/$environment->github_owner/$environment->github_repo/deployments";
      $deployment_data = json_decode($client->getHttpClient()->post($post_url, array(), json_encode($deployment))->getBody(TRUE));

      watchdog('devshop_github', 'New Deployment saved: ' . print_r($deployment_data, 1));

      self::saveDeployment($deployment_data, $environment->site);

    }
    else {
      $deployment_data = current($environment->github_deployments);
      watchdog('devshop_github', 'Existing Deployment loaded: ' . print_r($deployment_data, 1));
    }

    // Deployment Status
    $deployment_status = new stdClass();
    $deployment_status->state = $state;
    $deployment_status->target_url = 'http://' . $environment->uri;
    $deployment_status->log_url = empty($log_url)? $environment->dashboard_url: $log_url;

    // @TODO: Generate a default description?
    $deployment_status->description = $description;

    // @TODO: Use developer preview to get this:
    // https://developer.github.com/v3/previews/#deployment-statuses
    // https://developer.github.com/v3/previews/#enhanced-deployments
    $deployment_status->environment = $deployment_data->environment;
    $deployment_status->environment_url = $environment->url;

    // Create Deployment Status
    $post_url = "/repos/{$environment->github_owner}/{$environment->github_repo}/deployments/{$deployment_data->id}/statuses";
    $deployment_status_data = json_decode($client->getHttpClient()->post($post_url, array(), json_encode($deployment_status))->getBody(TRUE));

    watchdog('devshop_github', 'Deployment status saved: ' . print_r($deployment_status_data, 1));
    }
    catch (\Exception $e) {
      watchdog('devshop_github', 'GitHub Error: ' . $e->getMessage() . ' | Post URL: ' . $post_url . ' | '. $e->getTraceAsString());
      return false;
    }
    catch (Github\Exception\RuntimeException $e) {
      watchdog('devshop_github', 'GitHub Error: ' . $e->getMessage() . ' | Post URL: ' . $post_url . ' | '. $e->getTraceAsString());
      if ($e->getCode() == '409') {
//        $message .= "\n Branch is out of date! Merge code from base branch.";

        // Send a failed commit status to alert to developer
        $params = new stdClass();
        $params->state = 'failure';
        $params->target_url = $project->git_repo_url;
        $params->description = t('Branch is out of date! Merge from default branch.');
        $params->context = "devshop/{$project->name}/merge";

        // Post status to github
        $deployment_status = $client->getHttpClient()->post("/repos/$environment->github_owner/$environment->github_repo/statuses/$sha", array(), json_encode($params));
        watchdog('devshop_github', 'Deployment status saved: ' . $deployment_status);

      }

    } catch (Github\Exception\ValidationFailedException $e) {
      watchdog('devshop_github', 'GitHub Validation Failed Error: ' . $e->getMessage());
//      $message .= 'GitHub ValidationFailedException Error: ' . $e->getMessage();
    }
    devshop_github_save_pr_env_data( $environment->github_pull_request->pull_request_object, $environment);
  }

  /**
   * Save Deployment. Deployments never get updated.
   *
   * @param $project_nid
   * @param $environment_name
   * @param $deployment_id
   */
  public static function saveDeployment($deployment, $site_nid)  {
    $record = new stdClass();
    $record->deployment_id = $deployment->id;
    $record->site_nid = $site_nid;
    $record->deployment_object = serialize($deployment);
    drupal_write_record('hosting_devshop_github_deployments', $record);
  }
}
