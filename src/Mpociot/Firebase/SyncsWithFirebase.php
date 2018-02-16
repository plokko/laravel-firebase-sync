<?php

namespace Mpociot\Firebase;

use Firebase\FirebaseInterface;
use Firebase\FirebaseLib;

/**
 * SyncsWithFirebase Trait
 * @package App\Traits
 * @property  $firebaseSyncRelated array Relations to sync with Firebase (Note: related models must use SyncWithFirebase trait)
 */
trait SyncsWithFirebase
{

    /**
     * @var FirebaseInterface|null
     */
    protected $firebaseClient;

    /**
     * Boot the trait and add the model events to synchronize with firebase
     */
    public static function bootSyncsWithFirebase()
    {
        static::created(function ($model) {
            $model->saveToFirebase('set');
            $this->syncRelatedWithFirebase();
        });
        static::updated(function ($model) {
            $model->saveToFirebase('update');
            $this->syncRelatedWithFirebase();
        });
        static::deleted(function ($model) {
            $model->saveToFirebase('delete');
            $this->syncRelatedWithFirebase();
        });
        if(in_array('Illuminate\Database\Eloquent\SoftDeletes', class_uses(self::class))){
            static::restored(function ($model) {
                $model->saveToFirebase('set');
                $this->syncRelatedWithFirebase();
            });
        }
    }

    /**
     * @param FirebaseInterface|null $firebaseClient
     */
    public function setFirebaseClient($firebaseClient)
    {
        $this->firebaseClient = $firebaseClient;
    }

    /**
     * @return array
     */
    protected function getFirebaseSyncData()
    {
        if ($fresh = $this->fresh()) {
            return $fresh->toArray();
        }
        return [];
    }
    
    /**
     * Sync related models with Firebase (see $firebaseSyncRelated property)
     */
    function syncRelatedWithFirebase(){
        if(!empty($this->firebaseSyncRelated)){
            foreach($this->firebaseSyncRelated AS $k=>$v){
                $data = $related = null;
                if(is_string($v)){
                    $data = $this->{$v};
                    $related = $this->{$v}()->getRelated();
                }elseif(is_callable($v)){
                    $query = $v($this->{$k}());
                    if(!$query)
                        continue;
                    $data = $query->get();
                    $related = $this->{$k}()->getRelated();
                }
                if(!$data instanceof SyncsWithFirebaseCollection && in_array(SyncsWithFirebase::class,class_uses(self::class))){
                    throw new UnexpectedValueException('Unable to sync relation with firebase: related model '.get_class($related).' does not use SyncsWithFirebase trait');
                }
                $data->syncWithFirebase();
            }
        }
    }
    
    /**
     * Manually sync to firebase
     */
    public function syncWithFirebase(){
        $this->saveToFirebase('update');
    }

    /**
     * Automatically casts Collection to SyncsWithFirebaseCollection
     * to allow bulk syncWithFirebase
     * @param array $models
     * @return SyncsWithFirebaseCollection
     */
    public function newCollection(array $models = [])
    {
        return new SyncsWithFirebaseCollection($models);
    }
    
    /**
     * @param $mode
     */
    protected function saveToFirebase($mode)
    {
        if (is_null($this->firebaseClient)) {
            $this->firebaseClient = new FirebaseLib(config('services.firebase.database_url'), config('services.firebase.secret'));
        }
        $path = $this->getTable() . '/' . $this->getKey();

        if ($mode === 'set') {
            $this->firebaseClient->set($path, $this->getFirebaseSyncData());
        } elseif ($mode === 'update') {
            $this->firebaseClient->update($path, $this->getFirebaseSyncData());
        } elseif ($mode === 'delete') {
            $this->firebaseClient->delete($path);
        }
    }
}
