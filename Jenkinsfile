#!/usr/bin/env groovy

def FOO = credentials('magento')

pipeline {

  environment {
    MAGENTO = credentials('magento')
  }

  agent {
    dockerfile {
      additionalBuildArgs "--build-arg REPOUSER=${env.MAGENTO_USR} --build-arg REPOPASS=${FOO}"
    }
  }

  stages {
    stage('Prepare environment') {
      steps {
        checkout scm
      }
    }
    stage('Code Sniffer') {
      steps {
        catchError {
          sh "./vendor/bin/phpcbf --standard=ruleset.xml || true"
          sh "./vendor/bin/phpcs --standard=ruleset.xml --report=checkstyle --report-file=phpcs.xml || true"
        }
        step([$class: 'hudson.plugins.checkstyle.CheckStylePublisher', pattern: 'phpcs.xml', unstableTotalAll:'0'])
      }
    }
    stage('Mess Detection') {
      steps {
        catchError {
          sh "./vendor/bin/phpmd . xml codesize,naming,unusedcode,controversial,design --exclude vendor,var,build,tests --reportfile phpmd.xml || true"
        }
        //step([$class: 'PmdPublisher', pattern: 'phpmd.xml', unstableTotalAll:'0'])
      }
    }
    stage('Phan Analysis') {
      steps {
        catchError {
          sh "./vendor/bin/phan --config-file=phan.php --output-mode=checkstyle --output=phan.xml || true"
        }
        step([$class: 'hudson.plugins.checkstyle.CheckStylePublisher', pattern: 'phan.xml', unstableTotalAll:'0'])
      }
    }
    stage('Package') {
      steps {
        script {
          version = sh(returnStdout: true, script: 'git rev-parse --short HEAD').trim()
          sh "composer archive --format=zip --file=${version}"
          sh "composer validate-archive -- ${version}.zip"
        }
        archiveArtifacts "${version}.zip"
      }
    }
  }
}
