<?php
namespace Entity;

use \Doctrine\Common\Collections\ArrayCollection;

/**
 * @Table(name="songs", indexes={
 *   @index(name="search_idx", columns={"text", "artist", "title"})
 * })
 * @Entity
 * @HasLifecycleCallbacks
 */
class Song extends \DF\Doctrine\Entity
{
    const SYNC_THRESHOLD = 604800; // 604800 = 1 week

    public function __construct()
    {
        $this->created_at = new \DateTime('NOW');
        $this->score = 0;

        $this->votes = new ArrayCollection;
        $this->history = new ArrayCollection;
    }

    /** @PrePersist */
    public function preSave()
    {
        $this->id = self::getSongHash($this);
    }

    /**
     * @Column(name="id", type="string", length=50)
     * @Id
     */
    protected $id;

    /** @Column(name="text", type="string", length=150, nullable=true) */
    protected $text;

    /** @Column(name="artist", type="string", length=150, nullable=true) */
    protected $artist;

    /** @Column(name="title", type="string", length=150, nullable=true) */
    protected $title;

    /** @Column(name="created_at", type="datetime", nullable=true) */
    protected $created_at;

    /** @Column(name="score", type="smallint") */
    protected $score;

    public function updateScore()
    {
        $this->score = SongVote::getScore($this);
    }

    /* External Records */

    /** @Column(name="external_timestamp", type="integer", nullable=true) */
    protected $external_timestamp;


    /** @Column(name="external_ponyfm_id", type="integer", nullable=true) */
    protected $external_ponyfm_id;
    /**
     * @ManyToOne(targetEntity="SongExternalPonyFm")
     * @JoinColumns({ @JoinColumn(name="external_ponyfm_id", referencedColumnName="id", onDelete="CASCADE") })
     */
    protected $external_ponyfm;


    /** @Column(name="external_eqbeats_id", type="integer", nullable=true) */
    protected $external_eqbeats_id;
    /**
     * @ManyToOne(targetEntity="SongExternalEqBeats")
     * @JoinColumns({ @JoinColumn(name="external_eqbeats_id", referencedColumnName="id", onDelete="CASCADE") })
     */
    protected $external_eqbeats;


    /** @Column(name="external_bronytunes_id", type="integer", nullable=true) */
    protected $external_bronytunes_id;
    /**
     * @ManyToOne(targetEntity="SongExternalBronyTunes")
     * @JoinColumns({ @JoinColumn(name="external_bronytunes_id", referencedColumnName="id", onDelete="CASCADE") })
     */
    protected $external_bronytunes;


    public function hasExternal()
    {
        $adapters = self::getExternalAdapters();

        foreach($adapters as $adapter_key => $adapter_class)
        {
            $local_key = 'external_'.$adapter_key.'_id';
            if ($this->{$local_key} !== NULL)
                return true;
        }
        return false;
    }

    public function getExternal()
    {
        $adapters = self::getExternalAdapters();

        $external = array();
        foreach($adapters as $adapter_key => $adapter_class)
        {
            $local_key = 'external_'.$adapter_key;

            if ($this->{$local_key} instanceof $adapter_class)
            {
                $local_row = $this->{$local_key}->toArray();
                unset($local_row['__isInitialized__']);

                $external[$adapter_key] = $local_row;
            }
        }

        return $external;
    }

    public function syncExternal($force = false)
    {
        $threshold = time()-self::SYNC_THRESHOLD;
        if ($this->external_timestamp >= $threshold && !$force)
        {
            \PVL\Debug::log('Skipping external sync, has been synced recently.');
            return false;
        }

        $adapters = self::getExternalAdapters();

        foreach($adapters as $adapter_key => $remote_class)
        {
            $local_key = 'external_'.$adapter_key;
            $this->{$local_key} = $remote_class::match($this, $force);
        }

        $this->external_timestamp = time();
        $this->save();
        return true;
    }

    /* End External Records */

    /** 
     * @OneToMany(targetEntity="SongVote", mappedBy="song")
     * @OrderBy({"timestamp" = "DESC"})
     */
    protected $votes;

    /** 
     * @OneToMany(targetEntity="SongHistory", mappedBy="song")
     * @OrderBy({"timestamp" = "DESC"})
     */
    protected $history;

    /**
     * Static Functions
     */

    public static function getSongHash($song_info)
    {
        // Handle various input types.
        if ($song_info instanceof self)
        {
            $song_info = array(
                'text'  => $song_info->text,
                'artist' => $song_info->artist,
                'title' => $song_info->title,
            );
        }
        elseif (!is_array($song_info))
        {
            $song_info = array(
                'text' => $song_info,
            );
        }

        // Generate hash.
        if (!empty($song_info['text']))
            $song_text = $song_info['text'];
        else
            $song_text = $song_info['artist'].' - '.$song_info['title'];

        $hash_base = strtolower(preg_replace("/[^A-Za-z0-9]/", '', $song_text));
        return md5($hash_base);
    }

    public static function getOrCreate($song_info)
    {
        $song_hash = self::getSongHash($song_info);

        $obj = self::find($song_hash);

        if ($obj instanceof self)
        {
            return $obj;
        }
        else
        {
            if (!is_array($song_info))
                $song_info = array('text' => $song_info);

            $obj = new self;
            $obj->text = $song_info['text'];
            $obj->title = $song_info['title'];
            $obj->artist = $song_info['artist'];
            $obj->save();

            return $obj;
        }
    }

    // Retrieve the API version of the object/array.
    public static function api($row)
    {
        if ($row instanceof self)
            $row = $row->toArray();

        return array(
            'id'        => $row['id'],
            'text'      => $row['text'],
            'artist'    => $row['artist'],
            'title'     => $row['title'],
        );
    }

    public static function getExternalAdapters()
    {
        return array(
            'ponyfm'        => '\Entity\SongExternalPonyFm',
            'eqbeats'       => '\Entity\SongExternalEqBeats',
            'bronytunes'    => '\Entity\SongExternalBronyTunes',
        );
    }
}