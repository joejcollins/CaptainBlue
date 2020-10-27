<?php namespace App\Controllers;

/**
 * Manage the grid square views.
 */
class Squares extends BaseController
{
    private $data = array('title' => 'Squares');

    /**
     * Landing page showing the tetrads search form.
     */
    public function index()
    {
        echo view('squares_search', $this->data);
    }
 
}